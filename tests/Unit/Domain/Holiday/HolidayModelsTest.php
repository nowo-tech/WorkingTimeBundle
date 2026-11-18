<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Holiday;

use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;
use Nowo\WorkingTimeBundle\Domain\Holiday\Event\HolidaysImported;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarId;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayFetchResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreview;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreviewRow;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Holiday\WorkCentreId;
use Nowo\WorkingTimeBundle\Domain\Shared\DayAnnotation;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function mb_strlen;
use function str_repeat;

/**
 * The holiday component's enums, value objects and entities (contract sections 5 and 6.7).
 */
final class HolidayModelsTest extends TestCase
{
    public function testEnums(): void
    {
        self::assertSame(HolidayScope::Regional, HolidayScope::fromImport(' Autonomous '));
        self::assertSame(HolidayScope::Local, HolidayScope::fromImport('LOCAL'));
        self::assertNull(HolidayScope::fromImport('city'));
        self::assertSame('holiday.scope.national', HolidayScope::National->labelKey());
        self::assertSame(HolidayOrigin::FestivosIo, HolidayOrigin::ofProvider('festivos_io'));
        self::assertSame(HolidayOrigin::OpenHolidays, HolidayOrigin::ofProvider('openholidays'));
        self::assertSame(HolidayOrigin::Provider, HolidayOrigin::ofProvider('boe_feed'));
        self::assertSame('file', HolidayOrigin::Import->value, 'Beacon\'s stored value');
        self::assertSame('holiday.origin.manual', HolidayOrigin::Manual->labelKey());
        self::assertSame('holiday.import.status.other_scope', ImportStatus::OtherScope->labelKey());
    }

    public function testLocations(): void
    {
        $madrid = new HolidayLocation('es', 'es-md', '28079');
        self::assertSame('ES', $madrid->country);
        self::assertSame('ES-MD', $madrid->subdivision);
        self::assertSame('28079', $madrid->municipality);
        self::assertNull((new HolidayLocation('PT', ''))->subdivision);
        self::assertTrue(HolidayLocation::isMunicipalityCode('08019'));
        self::assertFalse(HolidayLocation::isMunicipalityCode('8019'));
        foreach ([['E', null, null, 'country'], ['ES', 'FR-75', null, 'subdivision'], ['ES', 'ES-AN-GR', null, 'subdivision'], ['ES', null, '2807', 'municipality']] as [$country, $subdivision, $town, $what]) {
            try {
                new HolidayLocation($country, $subdivision, $town);
                self::fail($what);
            } catch (InvalidArgumentException $e) {
                self::assertSame('holiday.location.error.' . $what, $e->getMessage());
            }
        }
    }

    public function testEntriesAreCleaned(): void
    {
        $entry = new HolidayEntry(new LocalDate('2026-05-15'), "San\u{0000} Isidro\u{200E}", HolidayScope::Local, HolidayOrigin::FestivosIo, ' BOCM-123 ', 'https://www.bocm.es/x');
        self::assertSame('San  Isidro', $entry->name);
        self::assertSame('BOCM-123', $entry->sourceRef);
        self::assertSame('https://www.bocm.es/x', $entry->sourceUrl);
        self::assertSame(120, mb_strlen((string) HolidayEntry::cleanName(str_repeat('á', 130))));
        self::assertNull(HolidayEntry::cleanName("\u{0001}\u{0002}"));
        self::assertNull(HolidayEntry::cleanName("\xC3\x28"));
        self::assertNull(HolidayEntry::cleanUrl('javascript:alert(1)'));
        self::assertNull(HolidayEntry::cleanUrl('https://x.example/a b'));
        self::assertNull(HolidayEntry::cleanUrl('https://x.example/' . str_repeat('a', 250)));
        self::assertSame('http://x.example/', HolidayEntry::cleanUrl('http://x.example/'));
        self::assertNull((new HolidayEntry(new LocalDate('2026-05-15'), 'X', sourceRef: "\u{0003}"))->sourceRef);
        $this->expectExceptionObject(new InvalidArgumentException('holiday.day.error.name'));
        new HolidayEntry(new LocalDate('2026-05-15'), ' ');
    }

    public function testValueObjects(): void
    {
        $id = new HolidayCalendarId(Fx::uuid(1));
        self::assertTrue($id->equals(new HolidayCalendarId(Fx::uuid(1))));
        self::assertTrue((new WorkCentreId(Fx::uuid(2)))->equals(new WorkCentreId(Fx::uuid(2))));
        $row     = new ImportPreviewRow('2026-01-01', 'New Year', ImportStatus::New, HolidayScope::National, 'BOE', 'https://boe.es');
        $preview = new ImportPreview([$row, $row->withStatus(ImportStatus::Duplicate)], null, new HolidayFetchResult([], 'https://festivos.io/x', 'Madrid', '2026-07-08', true, 'CC BY'));
        self::assertCount(1, $preview->with(ImportStatus::Duplicate));
        self::assertSame('BOE', $preview->with(ImportStatus::Duplicate)[0]->sourceRef);
        self::assertTrue($preview->fetched?->provisional);
        self::assertSame(2, (new ImportResult(2, 1, true))->added);
        self::assertSame('file', (new HolidaysImported(new TenantRef('t1'), $id, 'file', 2, 0))->source);
        self::assertSame(HolidayScope::Local, (new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026))->scope);
        self::assertTrue((new DayAnnotation(DayAnnotation::LEAVE, 'Vacation', false, 1800))->reducedSeconds === 1800);
        foreach ([['other', 0], [DayAnnotation::HOLIDAY, -1]] as [$kind, $reduced]) {
            try {
                new DayAnnotation($kind, 'x', true, $reduced);
                self::fail($kind);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testACalendar(): void
    {
        $calendar = HolidayCalendar::create(Fx::uuid(1), 't1', 'Madrid', HolidayScope::Local, 2026, ' BOCM ', new HolidayLocation('ES', 'ES-MD', '28079'), Fx::at('2026-01-01 10:00:00'));

        self::assertSame(Fx::uuid(1), $calendar->getId());
        self::assertSame('t1', $calendar->getTenantId());
        self::assertSame('Madrid', $calendar->getName());
        self::assertSame(HolidayScope::Local, $calendar->getScope());
        self::assertSame(2026, $calendar->getYear());
        self::assertSame('BOCM', $calendar->getSourceNote());
        self::assertSame('28079', $calendar->location()?->municipality);
        self::assertSame('ES', $calendar->getCountry());
        self::assertSame('ES-MD', $calendar->getSubdivision());
        self::assertSame('28079', $calendar->getMunicipalityCode());
        self::assertSame('manual', $calendar->getProvider());
        self::assertFalse($calendar->isProvisional());
        self::assertNull($calendar->getAttribution());
        self::assertNull($calendar->getLastFetchedAt());
        self::assertSame('2026-01-01', $calendar->getCreatedAt()->format('Y-m-d'));
        $calendar->recordFileImport();
        self::assertSame('file', $calendar->getProvider());
        $calendar->recordFetch('festivos_io', true, ' festivos.io — CC BY 4.0 ', 'https://festivos.io/v1/2026/municipio/28079.json', Fx::at('2026-11-18 09:00:00'));
        $calendar->recordFileImport();
        self::assertSame('festivos_io', $calendar->getProvider(), 'a file import keeps the provider');
        self::assertTrue($calendar->isProvisional());
        self::assertSame('festivos.io — CC BY 4.0', $calendar->getAttribution());
        self::assertSame('2026-11-18 09:00', $calendar->getLastFetchedAt()?->format('Y-m-d H:i'));
        $calendar->recordFetch('openholidays', false, null, null, Fx::at('2026-11-18 09:00:00'));
        self::assertSame('https://festivos.io/v1/2026/municipio/28079.json', $calendar->getSourceNote(), 'no new note keeps the old one');
        self::assertNull($calendar->getAttribution());
        $calendar->change('Madrid', HolidayScope::Local, 2027, null);
        self::assertNull($calendar->getSourceNote());
        $calendar->locate(null);
        self::assertNull($calendar->location());
        foreach ([['', 2026, 'holiday.calendar.error.name'], [str_repeat('n', 81), 2026, 'holiday.calendar.error.name'], ['Madrid', 1999, 'holiday.calendar.error.year'], ['Madrid', 2101, 'holiday.calendar.error.year']] as [$name, $year, $key]) {
            try {
                $calendar->change($name, HolidayScope::National, $year, null);
                self::fail($key);
            } catch (HolidayRefusedException $e) {
                self::assertSame($key, $e->key);
            }
        }
        $this->expectExceptionObject(new HolidayRefusedException('holiday.calendar.error.provider'));
        $calendar->recordFetch('Bad Key', false, null, null, Fx::at('2026-11-18'));
    }

    public function testADay(): void
    {
        $calendar = HolidayCalendar::create(Fx::uuid(1), 't1', 'Madrid', HolidayScope::Local, 2026, null, null, Fx::at('2026-01-01'));
        $day      = HolidayDay::create(Fx::uuid(2), $calendar, new LocalDate('2026-05-15'), 'San Isidro', HolidayOrigin::FestivosIo, 'BOCM-1', 'https://www.bocm.es/1');

        self::assertSame(Fx::uuid(2), $day->getId());
        self::assertSame('t1', $day->getTenantId());
        self::assertSame(Fx::uuid(1), $day->getCalendarId());
        self::assertSame('2026-05-15', $day->date()->value);
        self::assertSame('San Isidro', $day->getName());
        self::assertSame(HolidayOrigin::FestivosIo, $day->getOrigin());
        self::assertSame('BOCM-1', $day->getSourceRef());
        self::assertSame('https://www.bocm.es/1', $day->getSourceUrl());
        $entry = $day->entry($calendar);
        self::assertSame(HolidayScope::Local, $entry->scope);
        self::assertNull($day->getProvider(), 'built-in providers are told apart by their origin');
        $feed = HolidayDay::create(Fx::uuid(4), $calendar, new LocalDate('2026-03-01'), 'Feed', HolidayOrigin::Provider, null, null, 'feed_a');
        self::assertSame('feed_a', $feed->getProvider());
        self::assertSame('feed_a', $feed->entry($calendar)->provider);
        self::assertNull(HolidayDay::create(Fx::uuid(5), $calendar, new LocalDate('2026-03-02'), 'File', HolidayOrigin::Import, null, null, 'feed_a')->getProvider());
        self::assertSame(1, $calendar->getVersion());
        self::assertSame(Fx::uuid(1), $entry->calendarId);
        $day->change(new LocalDate('2026-05-14'), 'San Isidro (moved)');
        self::assertSame(HolidayOrigin::Manual, $day->getOrigin(), 'edited by hand: a manual day');
        self::assertNull($day->getSourceRef());
        self::assertNull($day->getSourceUrl());
        try {
            $day->change(new LocalDate('2026-05-14'), ' ');
            self::fail('empty name');
        } catch (HolidayRefusedException $e) {
            self::assertSame('holiday.day.error.name', $e->key);
        }
        $this->expectExceptionObject(new HolidayRefusedException('holiday.day.error.year'));
        HolidayDay::create(Fx::uuid(3), $calendar, new LocalDate('2027-01-01'), 'New Year');
    }

    public function testACentre(): void
    {
        $centre = WorkCentre::create(Fx::uuid(1), 't1', 'Madrid office', [Fx::uuid(2), Fx::uuid(3), Fx::uuid(2)], true);

        self::assertSame(Fx::uuid(1), $centre->getId());
        self::assertSame('t1', $centre->getTenantId());
        self::assertSame('Madrid office', $centre->getName());
        self::assertSame([Fx::uuid(2), Fx::uuid(3)], $centre->calendarIds());
        self::assertTrue($centre->covers(Fx::uuid(3)));
        self::assertTrue($centre->isDefault());
        $centre->forget(Fx::uuid(2));
        self::assertSame([Fx::uuid(3)], $centre->calendarIds());
        (new ReflectionProperty(WorkCentre::class, 'calendarIds'))->setValue($centre, [Fx::uuid(4), 7, 'not-a-uuid']);
        self::assertSame([Fx::uuid(4)], $centre->calendarIds(), 'a damaged json list keeps only well-formed ids');
        try {
            $centre->change(' ', [], false);
            self::fail('name');
        } catch (HolidayRefusedException $e) {
            self::assertSame('holiday.centre.error.name', $e->key);
        }
        $this->expectException(InvalidArgumentException::class);
        $centre->change('Office', ['nope'], false);
    }
}
