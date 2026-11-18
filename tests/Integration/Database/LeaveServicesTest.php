<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Application\Leave\DayCounter;
use Nowo\WorkingTimeBundle\Application\Leave\LeaveAccess;
use Nowo\WorkingTimeBundle\Application\Leave\StaleLeaveSettingsException;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\DayPart;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\Event\LeaveAllowanceAdjusted;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveAdministrationInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveBalanceReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ScheduleRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_keys;

/**
 * The leave foundations on every database (contract sections 6.6, 9.3 and 15.2; spec 204 FR-002 – FR-009): settings
 * (versioned, enabling seeds the types, needs the register), types (archive, a used unit is fixed), allowance policies
 * and lines with their expiry dates, the daily carry-over expiry, balances, and the day counter on dated schedules and
 * each day's work centre holidays.
 */
final class LeaveServicesTest extends RegisterTestCase
{
    public function testLeaveIsOffUntilEnabledAndTheGateDeniesByDefault(): void
    {
        $this->bootLeave();
        $admin = $this->admin();
        $this->expectFailure(NotAvailableException::class, static fn (): array => $admin->types(), 'leave off');
        $this->ctx()->grant('acme', 'ana', 'leave_manage');
        $this->expectFailure(AccessDeniedException::class, static fn (): array => $admin->settings(), 'no manage_settings');
        $this->ctx()->grant('acme', 'ana', 'manage_settings');
        [$settings, $version] = $admin->settings();
        self::assertFalse($settings->isEnabled());
        self::assertSame(0, $version);
        $this->expectFailure(LeaveRefusedException::class, static fn (): LeaveSettings => $admin->updateSettings(new LeaveSettingsInput(true), 0), 'register off');
        $this->enable(workers: ['ana', 'bea']);
        $this->ctx()->settingsRace = true;
        $this->expectFailure(StaleLeaveSettingsException::class, static fn (): LeaveSettings => $admin->updateSettings(new LeaveSettingsInput(true), 0), 'two first saves at once');
        $this->connection()->executeStatement('DELETE FROM nowo_working_time_leave_settings');
        $saved = $admin->updateSettings(new LeaveSettingsInput(true, RoundingMode::Up, 24), 0);
        self::assertTrue($saved->isEnabled());
        self::assertCount(13, $admin->types(), 'the default types are seeded');
        self::assertSame(0, $admin->seedTypes(), 'seeding again adds nothing');
        self::assertCount(1, $admin->policies(2026), 'the vacation default of the current year');
        self::assertSame(44, $admin->policies(2026)[0]->getAmount());
        [, $version] = $admin->settings();
        self::assertSame(1, $version);
        $this->expectFailure(StaleLeaveSettingsException::class, static fn (): LeaveSettings => $admin->updateSettings(new LeaveSettingsInput(true), 0), 'stale');
        self::assertFalse($admin->updateSettings(new LeaveSettingsInput(false), 1)->isEnabled());
        $this->expectFailure(NotAvailableException::class, static fn (): array => $admin->types(), 'switched off again');
        self::assertContains('working_time.leave_settings_changed', $this->recorder()->auditKeys());
        self::assertFalse($this->service(LeaveAccess::class)->can(WorkingTimePermission::LeaveManage));
        self::assertTrue($this->service(LeaveAccess::class)->can(WorkingTimePermission::ManageSettings, false));
    }

    public function testTypes(): void
    {
        $admin    = $this->leaveOn();
        $vacation = $this->typeBySeed('vacation');
        $custom   = $admin->saveType(null, new LeaveTypeInput('Training', true, AllowanceSource::Allowance, LeaveUnit::Hours, colour: 'sky'));

        self::assertSame('Training', $admin->type($custom->getId())->nameIn('en'));
        $admin->saveType($custom->getId(), new LeaveTypeInput('Training days', true, AllowanceSource::Allowance, LeaveUnit::WorkingDays, colour: 'sky'));
        self::assertSame(LeaveUnit::WorkingDays, $admin->type($custom->getId())->getUnit(), 'an unused type may change its unit');
        try {
            $admin->saveType($vacation->getId(), new LeaveTypeInput('Vacation', true, AllowanceSource::Allowance, LeaveUnit::Hours));
            self::fail('unit in use');
        } catch (LeaveRefusedException $e) {
            self::assertSame('leave.type.error.unit_in_use', $e->key);
        }
        $admin->archiveType($custom->getId(), true);
        self::assertTrue($admin->type($custom->getId())->isArchived());
        $admin->archiveType($custom->getId(), false);
        self::assertFalse($admin->type($custom->getId())->isArchived());
        self::assertSame('Vacaciones', $this->typeBySeed('vacation')->nameIn('es'), 'seeded in every locale');
        self::assertTrue($this->typeBySeed('medical')->isMedical());
        $this->expectFailure(NotAvailableException::class, static fn (): LeaveType => $admin->type('01900000-0000-7000-8000-000000000000'), 'unknown type');
    }

    public function testPoliciesLinesAndBalances(): void
    {
        $admin    = $this->leaveOn(['g1']);
        $vacation = $this->typeBySeed('vacation');
        $personal = $this->typeBySeed('personal');
        $events   = [];
        $this->dispatcher()->addListener(LeaveAllowanceAdjusted::class, static function (LeaveAllowanceAdjusted $event) use (&$events): void {
            $events[] = $event;
        });
        $group = $admin->addPolicy($vacation->getId(), ScheduleScope::Group, 'g1', 2026, 46);
        $admin->addPolicy($personal->getId(), ScheduleScope::Worker, 'bea', 2026, 4);
        $this->expectFailure(LeaveRefusedException::class, fn (): AllowancePolicy => $admin->addPolicy($this->typeBySeed('marriage')->getId(), ScheduleScope::Tenant, null, 2026, 10), 'not an allowance type');
        $this->expectFailure(LeaveRefusedException::class, static fn (): AllowancePolicy => $admin->addPolicy($vacation->getId(), ScheduleScope::Group, 'nope', 2026, 46), 'unknown group');
        $this->expectFailure(NotAvailableException::class, static fn (): AllowancePolicy => $admin->addPolicy($vacation->getId(), ScheduleScope::Worker, 'ghost', 2026, 46), 'not a member');
        $this->expectFailure(LeaveRefusedException::class, static fn (): AllowancePolicy => $admin->addPolicy($vacation->getId(), ScheduleScope::Tenant, null, 2026, 30), 'legal minimum');
        $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, -2, 'Correction of 2025');
        $protected = $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::ProtectedCarryOver, 6, 'Birth leave overlap');
        self::assertSame('2027-06-30', $protected->expiresOn()?->value, '18 months from 1 January by default');
        $this->expectFailure(LeaveRefusedException::class, static fn (): AllowanceLine => $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::CarryOver, 2, 'Not allowed'), 'carry-over off');
        $admin->saveType($vacation->getId(), new LeaveTypeInput('Vacation', true, AllowanceSource::Allowance, LeaveUnit::WorkingDays, carryOver: true, carryOverMax: 10, carryOverExpiry: '03-31'));
        $carry = $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::CarryOver, 10, 'From 2025');
        self::assertSame('2026-03-31', $carry->expiresOn()?->value);
        try {
            $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::CarryOver, 12, 'Too much');
            self::fail('carry max');
        } catch (LeaveRefusedException $e) {
            self::assertSame(['leave.allowance.error.carry_max', ['%max%' => '5']], [$e->key, $e->params]);
        }
        $this->expectFailure(LeaveRefusedException::class, fn (): AllowanceLine => $admin->addLine($this->typeBySeed('unpaid')->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, 2, 'No allowance'), 'not an allowance type');
        $this->expectFailure(NotAvailableException::class, static fn (): AllowanceLine => $admin->addLine($vacation->getId(), new WorkerRef('ghost'), 2026, AllowanceLineKind::Adjustment, 2, 'Ghost'), 'not a member');
        self::assertCount(3, $admin->lines(new WorkerRef('ana'), 2026));
        self::assertCount(3, $events);
        self::assertSame(AllowanceLineKind::CarryOver, $events[2]->kind);

        // Today is 2026-11-18: the carry-over expired on 31 March; the protected one still counts.
        $balances = $this->service(LeaveBalanceReaderInterface::class)->balances(new TenantRef('acme'), new WorkerRef('ana'), 2026);
        self::assertSame([$vacation->getId(), $personal->getId()], array_keys($balances));
        self::assertSame(46 - 2 + 6, $balances[$vacation->getId()]->entitled->amount, 'the group policy, the adjustment and the protected carry-over');
        self::assertSame('Vacation', $balances[$vacation->getId()]->typeName('en'));
        self::assertSame(0, $balances[$personal->getId()]->entitled->amount);
        self::assertSame($group->getId(), $balances[$vacation->getId()]->entitlement?->policy?->getId());
        $bea = $this->service(LeaveBalanceReaderInterface::class)->balances(new TenantRef('acme'), new WorkerRef('bea'), 2026);
        self::assertSame(44, $bea[$vacation->getId()]->entitled->amount, 'the tenant default');
        self::assertSame(4, $bea[$personal->getId()]->entitled->amount);
        self::assertSame([], $this->service(LeaveBalanceReaderInterface::class)->balances(new TenantRef('acme'), new WorkerRef('ghost'), 2026));
        self::assertSame([], $this->service(LeaveBalanceReaderInterface::class)->balances(new TenantRef('globex'), new WorkerRef('ana'), 2026), 'another tenant');
        $admin->archiveType($personal->getId(), true);
        self::assertArrayNotHasKey($personal->getId(), $this->service(LeaveBalanceReaderInterface::class)->balances(new TenantRef('acme'), new WorkerRef('ana'), 2026), 'an archived type holding nothing is hidden');
        $admin->removePolicy($group->getId());
        self::assertSame(44 - 2 + 6, $this->service(LeaveBalanceReaderInterface::class)->balances(new TenantRef('acme'), new WorkerRef('ana'), 2026)[$vacation->getId()]->entitled->amount, 'back to the tenant default');
        $this->expectFailure(NotAvailableException::class, static fn () => $admin->removePolicy($group->getId()), 'gone');

        // The daily job marks the expired carry-over once, with one audit row per tenant.
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:leave:daily'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 carry-over line(s) expired.', $tester->getDisplay());
        $tester->execute([]);
        self::assertStringContainsString('0 carry-over line(s) expired.', $tester->getDisplay());
        self::assertContains('working_time.leave_carry_over_expired', $this->recorder()->auditKeys());
        self::assertNotNull($admin->lines(new WorkerRef('ana'), 2026)[2]->getExpiredAt());
    }

    public function testTheDayCounterFollowsTheScheduleAndEachDaysCentre(): void
    {
        $this->leaveOn();
        $this->ctx()->grant('acme', 'ana', 'holidays_manage', 'manage_team');
        $holidays = $this->service(HolidayCalendarServiceInterface::class);
        $spain    = $holidays->saveCalendar(null, new HolidayCalendarInput('Spain', HolidayScope::National, 2026));
        $madrid   = $holidays->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026));
        $holidays->addDay($spain->getId(), new LocalDate('2026-12-08'), 'Inmaculada');
        $holidays->addDay($madrid->getId(), new LocalDate('2026-12-07'), 'Local Monday');
        $holidays->saveCentre(null, 'Madrid office', [$spain->getId(), $madrid->getId()], true);
        $bcn       = $holidays->saveCentre(null, 'Barcelona office', [$spain->getId()], false);
        $week      = new ScheduleDefinition('Europe/Madrid', [['day' => 1, 'from' => '09:00', 'to' => '17:00'], ['day' => 2, 'from' => '09:00', 'to' => '17:00'], ['day' => 3, 'from' => '09:00', 'to' => '17:00'], ['day' => 4, 'from' => '09:00', 'to' => '17:00'], ['day' => 5, 'from' => '09:00', 'to' => '17:00']]);
        $schedules = $this->service(ScheduleServiceInterface::class);
        $schedules->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-18'), $week);
        $schedules->assign(ScheduleScope::Worker, 'ana', new LocalDate('2026-12-03'), $week, $bcn->getId());
        try {
            $schedules->assign(ScheduleScope::Worker, 'ana', new LocalDate('2026-12-03'), $week, '01900000-0000-7000-8000-000000000000');
            self::fail('unknown centre');
        } catch (ScheduleRefusedException $e) {
            self::assertSame('schedule.error.work_centre', $e->key);
        }
        $counter  = $this->service(DayCounter::class);
        $settings = $this->access()->settings(new TenantRef('acme'));
        $from     = new LocalDate('2026-11-30');
        $to       = new LocalDate('2026-12-11');
        $ana      = $this->profile('ana');
        $bea      = $this->profile('bea');

        $count = $counter->count($settings, $ana, LeaveUnit::WorkingDays, $from, $to);
        self::assertSame(18, $count->amount, 'Ana moves to Barcelona on 3 December: 7 December is a working day for her');
        self::assertSame(['2026-12-08' => 'Inmaculada'], $count->holidays);
        self::assertSame(16, $counter->count($settings, $bea, LeaveUnit::WorkingDays, $from, $to)->amount, 'Bea stays in the default (Madrid) centre');
        self::assertSame(24, $counter->count($settings, $bea, LeaveUnit::NaturalDays, $from, $to)->amount);
        self::assertSame(8 * 120, $counter->count($settings, $bea, LeaveUnit::Hours, $from, $to, DayPart::Hours, '09:00', '11:00')->amount);
        self::assertSame(1, $counter->count($settings, $bea, LeaveUnit::WorkingDays, new LocalDate('2026-12-09'), new LocalDate('2026-12-09'), DayPart::Morning)->amount);
        $this->expectFailure(LeaveRefusedException::class, static fn () => $counter->count($settings, $bea, LeaveUnit::WorkingDays, $to, $from), 'end before start');
    }

    /**
     * Boots with leave on in the configuration (off for the tenant) and Ana as a member.
     *
     * @param list<string> $groups
     */
    private function bootLeave(array $groups = []): void
    {
        $this->boot(['leave' => ['enabled' => true]]);
        $this->ctx()->member('acme', 'ana', 'Ana', $groups);
        $this->ctx()->member('acme', 'bea', 'Bea');
        $this->ctx()->groups['acme'] = [[new GroupRef('g1'), 'Group one']];
        $this->ctx()->actor          = 'ana';
    }

    /**
     * Leave on for acme (register on, seeded), Ana with leave_manage and manage_settings.
     *
     * @param list<string> $groups
     */
    private function leaveOn(array $groups = []): LeaveAdministrationInterface
    {
        $this->bootLeave($groups);
        $this->enable(workers: []);
        $this->actAs('ana', 'leave_manage', 'manage_settings');
        $this->admin()->updateSettings(new LeaveSettingsInput(true), 0);

        return $this->admin();
    }

    private function admin(): LeaveAdministrationInterface
    {
        return $this->service(LeaveAdministrationInterface::class);
    }

    private function typeBySeed(string $code): LeaveType
    {
        foreach ($this->admin()->types() as $type) {
            if ($type->getSeedCode() === $code) {
                return $type;
            }
        }
        self::fail('no seeded ' . $code);
    }

    private function profile(string $worker): WorkerProfile
    {
        $profile = $this->ctx()->members['acme'][$worker] ?? null;
        self::assertInstanceOf(WorkerProfile::class, $profile);

        return $profile;
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
    private function expectFailure(string $class, callable $call, string $why): void
    {
        try {
            $call();
            self::fail('expected ' . $class . ': ' . $why);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $why . ' — ' . $e->getMessage());
        }
    }
}
