<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeDispatcher;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAnnotations;
use Nowo\WorkingTimeBundle\Application\Register\WorkerPreferences;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\DayAnnotation;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function array_filter;
use function array_values;

/**
 * Register day annotations for holidays (contract sections 8.2 and 19 S2): a public holiday of the worker's calendars
 * shows on the month view, asks for no clock reminder and is no first working day for the monthly summary; the
 * schedule form names the work centre whose calendars apply.
 */
final class HolidayRegisterTest extends UiTestCase
{
    public function testHolidaysMarkTheRegisterDays(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia'], new RegisterSettingsInput(enabled: true, remindersAllowed: true));
        $this->actAs('mia', 'manage_team', 'holidays_manage');
        $holidays = $this->service(HolidayCalendarServiceInterface::class);
        $calendar = $holidays->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026));
        $holidays->addDay($calendar->getId(), new LocalDate('2026-11-19'), 'Fiesta local');
        $holidays->addDay($calendar->getId(), new LocalDate('2026-12-01'), 'Primero de diciembre');
        $centre = $holidays->saveCentre(null, 'Madrid office', [$calendar->getId()], false);

        // The schedule form offers the work centres.
        $crawler = $this->client->request('GET', '/working-time/schedules');
        self::assertCount(1, $crawler->filter('select[name="wt_schedule[workCentre]"] option[value="' . $centre->getId() . '"]'));
        $week = [];
        for ($day = 1; $day <= 5; ++$day) {
            $week[] = ['day' => $day, 'from' => '09:00', 'to' => '17:00'];
        }
        $this->service(ScheduleServiceInterface::class)->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-18'), new ScheduleDefinition('UTC', $week), $centre->getId());

        // The month view names the holiday (no workday needed).
        $this->actAs('ana', 'clock');
        $crawler = $this->client->request('GET', '/working-time/month/2026-11');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Fiesta local', $crawler->text());
        $this->workDay();
        $crawler = $this->client->request('GET', '/working-time/month/2026-11');
        self::assertStringContainsString('2026-11-18', $crawler->text());
        self::assertStringContainsString('Fiesta local', $crawler->text());
        $annotations = $this->service(RegisterAnnotations::class);
        $profile     = $this->ctx()->members['acme']['ana'];
        self::assertInstanceOf(WorkerProfile::class, $profile);
        $settings = $this->access()->settings(new TenantRef('acme'));
        self::assertEquals(['2026-11-19' => [new DayAnnotation(DayAnnotation::HOLIDAY, 'Fiesta local')]], $annotations->between($settings, $profile, new LocalDate('2026-11-18'), new LocalDate('2026-11-30')));
        self::assertTrue($annotations->isDayOff($settings, $profile, new LocalDate('2026-11-19')));
        self::assertFalse($annotations->isDayOff($settings, $profile, new LocalDate('2026-11-20')));

        // No clock reminder on the holiday; one on the next working day. The summary waits for 2 December.
        $this->service(WorkerPreferences::class)->update(true, true);
        $dispatcher = $this->service(NoticeDispatcher::class);
        $this->clock()->modify('2026-11-19 08:50:00');
        $dispatcher->run();
        self::assertSame([], $this->kinds('register.reminder_in'), 'a holiday asks for no clocking');
        $this->clock()->modify('2026-11-20 08:50:00');
        $dispatcher->run();
        self::assertCount(1, $this->kinds('register.reminder_in'));
        $this->clock()->modify('2026-12-01 09:05:00');
        $dispatcher->run();
        self::assertSame([], $this->kinds('register.monthly_summary'), '1 December is a holiday');
        $this->clock()->modify('2026-12-02 09:05:00');
        $dispatcher->run();
        self::assertCount(1, $this->kinds('register.monthly_summary'));
    }

    /**
     * @return list<NoticeMessage>
     */
    private function kinds(string $kind): array
    {
        return array_values(array_filter($this->recorder()->sent, static fn (NoticeMessage $m): bool => $m->kind === $kind && $m->recipient->value === 'ana'));
    }
}
