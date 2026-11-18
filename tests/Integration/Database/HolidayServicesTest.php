<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Application\Holiday\HolidayAccess;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;
use Nowo\WorkingTimeBundle\Domain\Holiday\Event\HolidaysImported;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarId;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreview;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreviewRow;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\WorkCentreId;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Tests\Fixtures\RecordedHolidayApi;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_map;
use function count;

/**
 * The holiday component on every database (contract sections 6.7, 7, 9.2 and 15.2): calendars, days by hand,
 * CSV / ICS imports (preview, confirm checked again), provider imports from the recorded APIs (preview, confirm asks
 * again and replaces only that provider's days), work centres (one default), the stand-alone reader (centres,
 * calendars, the tenant's default), the deny-by-default gate and two tenants that never cross.
 */
final class HolidayServicesTest extends RegisterTestCase
{
    public function testTheGateIsDenyByDefault(): void
    {
        $this->boot();
        $service = $this->holidays();
        $this->expectGate(NotAvailableException::class, static fn (): array => $service->calendars());
        $this->ctx()->actor = 'ana';
        $this->expectGate(NotAvailableException::class, static fn (): array => $service->calendars(), 'not a member');
        $this->ctx()->member('acme', 'ana', 'Ana');
        $this->expectGate(AccessDeniedException::class, static fn (): array => $service->calendars());
        self::assertFalse($this->service(HolidayAccess::class)->can());
        $this->actAs('ana', 'holidays_manage');
        self::assertSame([], $service->calendars());
        self::assertTrue($this->service(HolidayAccess::class)->can());
        $this->ctx()->tenant = null;
        $this->expectGate(NotAvailableException::class, static fn (): array => $service->calendars(), 'no tenant');
    }

    public function testCalendarsAndDaysByHand(): void
    {
        $this->manager();
        $service  = $this->holidays();
        $calendar = $service->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026, 'BOCM', new HolidayLocation('ES', 'ES-MD', '28079')));
        $day      = $service->addDay($calendar->getId(), new LocalDate('2026-05-15'), 'San Isidro');
        $service->addDay($calendar->getId(), new LocalDate('2026-11-09'), 'Almudena');

        self::assertSame(['Madrid'], array_map(static fn (HolidayCalendar $c): string => $c->getName(), $service->calendars()));
        self::assertSame('28079', $service->calendar($calendar->getId())->getMunicipalityCode());
        self::assertSame(['2026-05-15', '2026-11-09'], array_map(static fn (HolidayDay $d): string => $d->date()->value, $service->days($calendar->getId())));
        $this->refused('holiday.day.error.duplicate', static fn (): HolidayDay => $service->addDay($calendar->getId(), new LocalDate('2026-05-15'), 'Again'));
        $this->refused('holiday.day.error.year', static fn (): HolidayDay => $service->addDay($calendar->getId(), new LocalDate('2027-05-15'), 'Next year'));
        $this->refused('holiday.day.error.year', static fn () => $service->changeDay($calendar->getId(), $day->getId(), new LocalDate('2027-05-15'), 'Next year'));
        $this->refused('holiday.day.error.duplicate', static fn () => $service->changeDay($calendar->getId(), $day->getId(), new LocalDate('2026-11-09'), 'Clash'));
        $service->changeDay($calendar->getId(), $day->getId(), new LocalDate('2026-05-14'), 'San Isidro (moved)');
        self::assertSame('San Isidro (moved)', $service->days($calendar->getId())[0]->getName());
        $this->refused('holiday.calendar.error.year_in_use', static fn (): HolidayCalendar => $service->saveCalendar($calendar->getId(), new HolidayCalendarInput('Madrid', HolidayScope::Local, 2027)));
        $service->saveCalendar($calendar->getId(), new HolidayCalendarInput('Madrid city', HolidayScope::Local, 2026));
        self::assertNull($service->calendar($calendar->getId())->location(), 'no place given: cleared');
        $service->removeDay($calendar->getId(), $day->getId());
        self::assertCount(1, $service->days($calendar->getId()));
        $this->expectGate(NotAvailableException::class, static fn () => $service->removeDay($calendar->getId(), $day->getId()));
        $this->expectGate(NotAvailableException::class, static fn () => $service->changeDay($calendar->getId(), $day->getId(), new LocalDate('2026-05-14'), 'X'));
        $this->expectGate(NotAvailableException::class, static fn (): HolidayCalendar => $service->calendar('01900000-0000-7000-8000-000000000000'));
        self::assertContains('working_time.holidays_changed', $this->recorder()->auditKeys());
    }

    public function testAFileImportIsPreviewedAndCheckedAgain(): void
    {
        $this->manager();
        $service  = $this->holidays();
        $calendar = $service->saveCalendar(null, new HolidayCalendarInput('Spain', HolidayScope::National, 2026));
        $service->addDay($calendar->getId(), new LocalDate('2026-01-01'), 'Año Nuevo');
        $preview = $service->previewFile($calendar->getId(), "date,name,scope\n2026-01-01,Año Nuevo,national\n2026-01-06,Reyes,national\n2026-05-02,Madrid,regional\n", 'spain.csv');

        self::assertSame([ImportStatus::Duplicate, ImportStatus::New, ImportStatus::OtherScope], array_map(static fn (ImportPreviewRow $r): ImportStatus => $r->status, $preview->rows));
        self::assertSame(0, count($service->days($calendar->getId())) - 1, 'a preview saves nothing');
        $result = $service->confirmFile($calendar->getId(), [
            ['date' => '2026-01-06', 'name' => 'Reyes'],
            ['date' => '2026-01-06', 'name' => 'Twice'],
            ['date' => '2026-01-01', 'name' => 'Taken'],
            ['date' => '2027-01-01', 'name' => 'Other year'],
            ['date' => 'soon', 'name' => 'Bad'],
            ['date' => '2026-03-19', 'name' => ' '],
        ]);
        self::assertSame(1, $result->added);
        self::assertSame(HolidayOrigin::Import, $service->days($calendar->getId())[1]->getOrigin());
        self::assertSame('file', $service->calendar($calendar->getId())->getProvider());
        $this->refused('holiday.import.error.too_many', static fn (): ImportResult => $service->confirmFile($calendar->getId(), array_fill(0, 201, ['date' => '2026-01-01', 'name' => 'X'])));
    }

    public function testFileImportsCanBeSwitchedOff(): void
    {
        $this->manager(['holidays' => ['providers' => ['file' => ['enabled' => false]]]]);
        $service  = $this->holidays();
        $calendar = $service->saveCalendar(null, new HolidayCalendarInput('Spain', HolidayScope::National, 2026));
        $this->refused('holiday.import.error.disabled', static fn (): ImportPreview => $service->previewFile($calendar->getId(), "date,name\n2026-01-01,X", 'x.csv'));
        $this->refused('holiday.import.error.disabled', static fn (): ImportResult => $service->confirmFile($calendar->getId(), []));
    }

    public function testAFestivosIoImportSyncsOnlyItsOwnDays(): void
    {
        $this->manager();
        $service = $this->holidays();
        $events  = [];
        $this->dispatcher()->addListener(HolidaysImported::class, static function (HolidaysImported $event) use (&$events): void {
            $events[] = $event;
        });
        $calendar = $service->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026));
        $service->addDay($calendar->getId(), new LocalDate('2026-05-15'), 'San Isidro (by hand)');
        $madrid  = new HolidayLocation('ES', 'ES-MD', '28079');
        $preview = $service->previewProvider($calendar->getId(), 'festivos_io', $madrid, 'es');

        self::assertCount(13, $preview->with(ImportStatus::New));
        self::assertCount(1, $preview->with(ImportStatus::Duplicate), 'the day by hand is kept');
        self::assertSame('Madrid', $preview->fetched?->place);
        self::assertCount(1, $service->days($calendar->getId()), 'a preview saves nothing');
        $result = $service->confirmProvider($calendar->getId(), 'festivos_io', $madrid, 'es');
        self::assertSame(13, $result->added);
        self::assertSame(0, $result->removed);
        self::assertCount(14, $service->days($calendar->getId()));
        $stored = $service->calendar($calendar->getId());
        self::assertSame('festivos_io', $stored->getProvider());
        self::assertSame('28079', $stored->getMunicipalityCode());
        self::assertStringContainsString('CC BY 4.0', (string) $stored->getAttribution());
        self::assertStringContainsString('festivos.io/v1/2026/municipio/28079.json', (string) $stored->getSourceNote());
        self::assertSame('2026-11-18 10:00', $stored->getLastFetchedAt()?->format('Y-m-d H:i'));
        self::assertCount(1, $events);
        self::assertSame(13, $events[0]->added);
        // A second confirm changes nothing; an OpenHolidays sync never touches festivos.io's days.
        self::assertSame(0, $service->confirmProvider($calendar->getId(), 'festivos_io', $madrid, 'es')->added);
        $open = $service->confirmProvider($calendar->getId(), 'openholidays', new HolidayLocation('ES', 'ES-MD'), 'es');
        self::assertSame(1, $open->added, 'only 25 July is not there yet');
        self::assertCount(15, $service->days($calendar->getId()));
        $this->refused('holiday.provider.error.unknown', static fn (): ImportPreview => $service->previewProvider($calendar->getId(), 'nope', $madrid, 'es'));
        $this->refused('holiday.provider.error.location', static fn (): ImportPreview => $service->previewProvider($calendar->getId(), 'festivos_io', new HolidayLocation('ES'), 'es'));
        $this->api()->fail = '404';
        $this->refused('holiday.provider.festivos_io.error', static fn (): ImportResult => $service->confirmProvider($calendar->getId(), 'festivos_io', $madrid, 'es'));
        self::assertCount(15, $service->days($calendar->getId()), 'a failure saves nothing');
        foreach ($this->api()->requests as $request) {
            self::assertStringNotContainsString('ana', $request, 'no personal data leaves the server');
        }
    }

    public function testAProvisionalYearAndDaysAProviderNoLongerLists(): void
    {
        $this->manager();
        $service           = $this->holidays();
        $calendar          = $service->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2027));
        $madrid            = new HolidayLocation('ES', null, '28079');
        $this->api()->fail = 'year';
        self::assertTrue($service->confirmProvider($calendar->getId(), 'festivos_io', $madrid, 'es')->provisional);
        self::assertTrue($service->calendar($calendar->getId())->isProvisional());
        $other             = $service->saveCalendar(null, new HolidayCalendarInput('Spain', HolidayScope::National, 2026));
        $this->api()->fail = null;
        $service->confirmProvider($other->getId(), 'openholidays', new HolidayLocation('ES'), 'es');
        $before = count($service->days($other->getId()));
        $result = $service->confirmProvider($other->getId(), 'openholidays', new HolidayLocation('ES', 'ES-MD'), 'es');
        self::assertSame($before, $result->removed, 'the days the new list lacks are removed');
        self::assertSame(5, $result->added);
        self::assertCount(5, $service->days($other->getId()));
    }

    public function testWorkCentresAndTheStandAloneReader(): void
    {
        $this->manager();
        $service = $this->holidays();
        $spain   = $service->saveCalendar(null, new HolidayCalendarInput('Spain', HolidayScope::National, 2026));
        $madrid  = $service->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026));
        $bcn     = $service->saveCalendar(null, new HolidayCalendarInput('Barcelona', HolidayScope::Local, 2026));
        $service->addDay($spain->getId(), new LocalDate('2026-10-12'), 'Fiesta Nacional');
        $service->addDay($madrid->getId(), new LocalDate('2026-05-15'), 'San Isidro');
        $service->addDay($bcn->getId(), new LocalDate('2026-09-24'), 'La Mercè');
        $service->addDay($bcn->getId(), new LocalDate('2026-10-12'), 'Also national');
        $reader = $this->service(HolidayCalendarReaderInterface::class);
        $acme   = new TenantRef('acme');
        $year   = [new LocalDate('2026-01-01'), new LocalDate('2026-12-31')];

        self::assertCount(3, $reader->holidaysBetween($acme, null, ...$year), 'no centre: every calendar, one row per date');
        $mad = $service->saveCentre(null, 'Madrid office', [$spain->getId(), $madrid->getId(), '01900000-0000-7000-8000-000000000000'], true);
        self::assertSame([$spain->getId(), $madrid->getId()], $mad->calendarIds(), 'only the tenant\'s calendars');
        $bar = $service->saveCentre(null, 'Barcelona office', [$spain->getId(), $bcn->getId()], false);
        self::assertSame(['2026-05-15', '2026-10-12'], array_map(static fn (HolidayEntry $e): string => $e->date->value, $reader->holidaysBetween($acme, null, ...$year)), 'the default centre');
        $bcnDays = $reader->holidaysBetween($acme, new WorkCentreId($bar->getId()), ...$year);
        self::assertSame(['2026-09-24', '2026-10-12'], array_map(static fn (HolidayEntry $e): string => $e->date->value, $bcnDays));
        self::assertSame('Also national', $bcnDays[1]->name, 'the first calendar by name wins');
        self::assertSame(HolidayScope::Local, $bcnDays[0]->scope);
        self::assertSame(['2026-09-24'], array_map(static fn (HolidayEntry $e): string => $e->date->value, $reader->holidaysBetween($acme, [new HolidayCalendarId($bcn->getId())], new LocalDate('2026-09-01'), new LocalDate('2026-09-30'))));
        self::assertTrue($reader->isHoliday($acme, null, new LocalDate('2026-05-15')));
        self::assertFalse($reader->isHoliday($acme, new WorkCentreId($bar->getId()), new LocalDate('2026-05-15')));
        self::assertSame([], $reader->holidaysBetween($acme, new WorkCentreId('01900000-0000-7000-8000-000000000000'), ...$year), 'an unknown centre has no holidays');
        self::assertSame([], $reader->holidaysBetween($acme, [], ...$year));
        self::assertSame([], $reader->holidaysBetween($acme, null, new LocalDate('2026-12-31'), new LocalDate('2026-01-01')));
        self::assertSame(['2026-05-15' => 'San Isidro', '2026-09-24' => 'La Mercè'], $reader->holidayMap($acme, ['2026-05-15' => null, '2026-09-24' => $bar->getId(), '2026-09-25' => 'gone'], new LocalDate('2026-05-01'), new LocalDate('2026-09-30')));
        self::assertSame([], $reader->holidayMap($acme, [], new LocalDate('2026-02-01'), new LocalDate('2026-02-28')));
        self::assertSame([], $reader->holidayMap($acme, [], new LocalDate('2026-02-28'), new LocalDate('2026-02-01')));
        self::assertSame(['Barcelona office', 'Madrid office'], array_map(static fn (WorkCentre $c): string => $c->getName(), $reader->workCentres($acme)));
        // One default: making Barcelona the default clears Madrid's flag; removing a calendar leaves the centres.
        $service->saveCentre($bar->getId(), 'Barcelona office', [$spain->getId(), $bcn->getId()], true);
        self::assertFalse($service->centre($mad->getId())->isDefault());
        $service->removeCalendar($bcn->getId());
        self::assertSame([$spain->getId()], $service->centre($bar->getId())->calendarIds());
        $service->removeCentre($mad->getId());
        self::assertCount(1, $service->centres());
        $this->expectGate(NotAvailableException::class, static fn (): WorkCentre => $service->centre($mad->getId()));
        $this->expectGate(NotAvailableException::class, static fn (): WorkCentre => $service->saveCentre($mad->getId(), 'Ghost', [], false));
        $this->expectGate(NotAvailableException::class, static fn () => $service->removeCentre($mad->getId()));
        $this->expectGate(NotAvailableException::class, static fn () => $service->removeCalendar($bcn->getId()));
        // Another tenant sees nothing of acme.
        $this->ctx()->tenant = 'globex';
        $this->actAs('ana', 'holidays_manage');
        $this->ctx()->member('globex', 'ana', 'Ana');
        self::assertSame([], $service->calendars());
        self::assertSame([], $reader->holidaysBetween(new TenantRef('globex'), null, ...$year));
        $this->expectGate(NotAvailableException::class, static fn (): HolidayCalendar => $service->calendar($spain->getId()));
        $this->expectGate(NotAvailableException::class, static fn (): array => $service->days($spain->getId()));
    }

    /**
     * Acme with Ana as holiday manager.
     *
     * @param array<string, mixed> $config
     */
    private function manager(array $config = []): void
    {
        $this->boot($config);
        $this->ctx()->member('acme', 'ana', 'Ana');
        $this->actAs('ana', 'holidays_manage');
    }

    private function holidays(): HolidayCalendarServiceInterface
    {
        return $this->service(HolidayCalendarServiceInterface::class);
    }

    private function api(): RecordedHolidayApi
    {
        $api = $this->kernel->getContainer()->get('test.holiday_api');
        self::assertInstanceOf(RecordedHolidayApi::class, $api);

        return $api;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @param class-string<Throwable> $class
     */
    private function expectGate(string $class, callable $call, string $why = ''): void
    {
        try {
            $call();
            self::fail('expected ' . $class . ' ' . $why);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $why);
        }
    }

    private function refused(string $key, callable $call): void
    {
        try {
            $call();
            self::fail('expected ' . $key);
        } catch (HolidayRefusedException $e) {
            self::assertSame($key, $e->key);
        }
    }
}
