<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Balance;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\DayPart;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\Event\LeaveAllowanceAdjusted;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveAmount;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeId;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The leave foundations' enums, value objects and entities (contract sections 5 and 6.6).
 */
final class LeaveModelsTest extends TestCase
{
    public function testUnitsStoreHalfDaysAndMinutes(): void
    {
        self::assertSame(2, LeaveUnit::WorkingDays->perUnit());
        self::assertSame(60, LeaveUnit::Hours->perUnit());
        self::assertSame(45, LeaveUnit::NaturalDays->fromInput(22.5));
        self::assertSame(22.5, LeaveUnit::NaturalDays->toInput(45));
        self::assertSame(90, LeaveUnit::Hours->fromInput(1.5));
        self::assertSame('22', LeaveUnit::WorkingDays->format(44));
        self::assertSame('4.5', LeaveUnit::WorkingDays->format(9));
        self::assertSame('7:30', LeaveUnit::Hours->format(450));
        self::assertSame('-0:45', LeaveUnit::Hours->format(-45));
        self::assertFalse(LeaveUnit::Hours->isDays());
        self::assertSame('leave.unit.hours', LeaveUnit::Hours->labelKey());
        self::assertSame('leave.source.overtime', AllowanceSource::Overtime->labelKey());
        self::assertSame('leave.line_kind.carry_over', AllowanceLineKind::CarryOver->labelKey());
        self::assertSame('leave.part.morning', DayPart::Morning->labelKey());
        self::assertTrue(DayPart::Afternoon->isHalf());
        self::assertFalse(DayPart::Hours->isHalf());
        self::assertSame('leave.rounding.up', RoundingMode::Up->labelKey());
        self::assertSame(3, RoundingMode::Nearest->apply(2.5));
        self::assertSame(3, RoundingMode::Up->apply(2.1));
        self::assertSame(2, RoundingMode::Up->apply(2.0000000001));
        self::assertSame(2, RoundingMode::Down->apply(2.9));
        self::assertSame(3, RoundingMode::Down->apply(2.9999999999));
    }

    public function testValueObjects(): void
    {
        $id      = new LeaveTypeId(Fx::uuid(1));
        $balance = new Balance($id, LeaveUnit::WorkingDays, new LeaveAmount(LeaveUnit::WorkingDays, 44), new LeaveAmount(LeaveUnit::WorkingDays, 10), new LeaveAmount(LeaveUnit::WorkingDays, 4), new LeaveAmount(LeaveUnit::WorkingDays, 6), typeNames: ['en' => 'Vacation', 'es' => 'Vacaciones']);

        self::assertTrue($id->equals(new LeaveTypeId(Fx::uuid(1))));
        self::assertSame('15', $balance->remaining()->format());
        self::assertSame(24, $balance->available()->amount);
        self::assertSame('Vacaciones', $balance->typeName('es_ES'));
        self::assertSame('Vacation', $balance->typeName('fr'));
        $only = new Balance($id, LeaveUnit::Hours, new LeaveAmount(LeaveUnit::Hours, 0), new LeaveAmount(LeaveUnit::Hours, 0), new LeaveAmount(LeaveUnit::Hours, 0), new LeaveAmount(LeaveUnit::Hours, 0), typeNames: ['de' => 'Urlaub']);
        self::assertSame('Urlaub', $only->typeName('fr'));
        $none = new Balance($id, LeaveUnit::Hours, new LeaveAmount(LeaveUnit::Hours, 0), new LeaveAmount(LeaveUnit::Hours, 0), new LeaveAmount(LeaveUnit::Hours, 0), new LeaveAmount(LeaveUnit::Hours, 0));
        self::assertSame(Fx::uuid(1), $none->typeName('en'));
        $event = new LeaveAllowanceAdjusted(new TenantRef('t1'), new WorkerRef('ana'), $id, 2026, AllowanceLineKind::Adjustment, 2);
        self::assertSame(2, $event->amount);
    }

    public function testALeaveTypeKeepsItsSeededNamesUntilRenamed(): void
    {
        $type = LeaveType::create(Fx::uuid(1), 't1', new LeaveTypeInput('Vacation'), 'en', Fx::at('2026-01-01 10:00:00'), 'vacation', 10, ['en' => 'Vacation', 'es' => 'Vacaciones']);

        self::assertSame('Vacaciones', $type->nameIn('es'));
        self::assertSame('Vacaciones', $type->nameIn('es_ES'));
        self::assertSame('Vacation', $type->nameIn('fr'));
        self::assertSame(['en' => 'Vacation', 'es' => 'Vacaciones'], $type->names());
        $type->change(new LeaveTypeInput('Vacation'), 'en');
        self::assertSame('Vacaciones', $type->nameIn('es'), 'the same name keeps the translations');
        $type->change(new LeaveTypeInput('Holidays'), 'en');
        self::assertSame('Holidays', $type->nameIn('es'));
        self::assertSame('vacation', $type->getSeedCode());
        self::assertSame(10, $type->getSortOrder());
        self::assertSame('t1', $type->getTenantId());
        self::assertSame('2026-01-01 10:00:00', $type->getCreatedAt()->format('Y-m-d H:i:s'));
        $type->setArchived(true);
        self::assertTrue($type->isArchived());
        $fresh = LeaveType::create(Fx::uuid(2), 't1', new LeaveTypeInput('Absence'), 'de', Fx::at('2026-01-01'));
        self::assertSame('Absence', $fresh->nameIn('en'));
    }

    public function testALeaveTypeRoundTripsItsInput(): void
    {
        $input = new LeaveTypeInput("Vacation\u{200B}", true, AllowanceSource::Allowance, LeaveUnit::WorkingDays, false, true, false, 'teal', 20, true, 10, '03-31');
        $type  = LeaveType::create(Fx::uuid(1), 't1', $input, 'en', Fx::at('2026-01-01'));
        $back  = $type->input('en');

        self::assertSame('Vacation', $back->name, 'format characters are stripped');
        self::assertTrue($type->isPaid());
        self::assertSame(AllowanceSource::Allowance, $type->getSource());
        self::assertSame(LeaveUnit::WorkingDays, $type->getUnit());
        self::assertFalse($type->needsApproval());
        self::assertTrue($type->needsAttachment());
        self::assertFalse($type->isMedical());
        self::assertSame('teal', $type->getColour());
        self::assertSame(20, $type->getMaxPerRequest());
        self::assertTrue($type->allowsCarryOver());
        self::assertSame(10, $type->getCarryOverMax());
        self::assertSame('03-31', $type->getCarryOverExpiry());
        $type->change(new LeaveTypeInput('Vacation', source: AllowanceSource::None, carryOver: true, carryOverMax: 10, carryOverExpiry: '03-31'), 'en');
        self::assertFalse($type->allowsCarryOver(), 'carry-over only for allowance types');
        self::assertNull($type->getCarryOverMax());
        self::assertNull($type->getCarryOverExpiry());
    }

    /**
     * @return iterable<string, array{LeaveTypeInput, string}>
     */
    public static function badTypes(): iterable
    {
        yield 'empty name' => [new LeaveTypeInput(' '), 'leave.type.error.name'];
        yield 'long name' => [new LeaveTypeInput(str_repeat('x', 81)), 'leave.type.error.name'];
        yield 'colour' => [new LeaveTypeInput('A', colour: 'pink'), 'leave.type.error.colour'];
        yield 'max' => [new LeaveTypeInput('A', maxPerRequest: 0), 'leave.type.error.max'];
        yield 'overtime in days' => [new LeaveTypeInput('A', source: AllowanceSource::Overtime), 'leave.type.error.overtime_hours'];
        yield 'medical with an allowance' => [new LeaveTypeInput('A', source: AllowanceSource::Allowance, medical: true), 'leave.type.error.medical_source'];
        yield 'expiry text' => [new LeaveTypeInput('A', source: AllowanceSource::Allowance, carryOver: true, carryOverExpiry: 'March'), 'leave.type.error.expiry'];
        yield 'expiry date' => [new LeaveTypeInput('A', source: AllowanceSource::Allowance, carryOver: true, carryOverExpiry: '02-30'), 'leave.type.error.expiry'];
        yield 'carry max' => [new LeaveTypeInput('A', source: AllowanceSource::Allowance, carryOver: true, carryOverMax: 0), 'leave.type.error.carry_max'];
    }

    #[DataProvider('badTypes')]
    public function testALeaveTypeRefusesBadAttributes(LeaveTypeInput $input, string $key): void
    {
        $this->expectExceptionObject(new LeaveRefusedException($key));
        LeaveType::create(Fx::uuid(1), 't1', $input, 'en', Fx::at('2026-01-01'));
    }

    public function testAPolicyChecksItsTargetAndTheLegalMinimum(): void
    {
        $vacation = LeaveType::create(Fx::uuid(1), 't1', new LeaveTypeInput('Vacation', source: AllowanceSource::Allowance), 'en', Fx::at('2026-01-01'), 'vacation');
        $hours    = LeaveType::create(Fx::uuid(2), 't1', new LeaveTypeInput('Hours', source: AllowanceSource::Allowance, unit: LeaveUnit::Hours), 'en', Fx::at('2026-01-01'), 'vacation');
        $policy   = AllowancePolicy::create(Fx::uuid(3), $vacation, ScheduleScope::Group, 'g1', 2026, 44, false, '  ', Fx::at('2026-01-01 08:00:00'), new ActorRef('hr'));

        self::assertSame(Fx::uuid(3), $policy->getId());
        self::assertSame('t1', $policy->getTenantId());
        self::assertSame(Fx::uuid(1), $policy->getLeaveTypeId());
        self::assertSame(ScheduleScope::Group, $policy->getScope());
        self::assertSame('g1', $policy->getTargetId());
        self::assertSame(2026, $policy->getYear());
        self::assertSame(44, $policy->getAmount());
        self::assertFalse($policy->isAgreementEquivalent());
        self::assertNull($policy->getNote());
        self::assertSame('hr', $policy->getCreatedById());
        self::assertSame('08:00', $policy->getCreatedAt()->format('H:i'));
        self::assertSame(60, AllowancePolicy::legalMinimum(LeaveUnit::NaturalDays));
        self::assertSame(0, AllowancePolicy::legalMinimum(LeaveUnit::Hours));
        self::assertSame(10, AllowancePolicy::create(Fx::uuid(4), $hours, ScheduleScope::Tenant, null, 2026, 10, false, null, Fx::at('2026-01-01'), null)->getAmount(), 'hour units: the agreement decides');
        self::assertSame(2, AllowancePolicy::create(Fx::uuid(5), $vacation, ScheduleScope::Worker, 'ana', 2026, 2, false, null, Fx::at('2026-01-01'), null)->getAmount(), 'a worker\'s own row may be anything');
        $equivalent = AllowancePolicy::create(Fx::uuid(6), $vacation, ScheduleScope::Tenant, null, 2026, 40, true, 'Agreement article 12', Fx::at('2026-01-01'), null);
        self::assertSame('Agreement article 12', $equivalent->getNote());
        foreach ([
            [ScheduleScope::Tenant, 'x', 2026, 44, false, null, 'leave.allowance.error.target'],
            [ScheduleScope::Group, null, 2026, 44, false, null, 'leave.allowance.error.target'],
            [ScheduleScope::Tenant, null, 2026, -1, false, null, 'leave.allowance.error.amount'],
            [ScheduleScope::Tenant, null, 1999, 44, false, null, 'leave.allowance.error.amount'],
            [ScheduleScope::Tenant, null, 2101, 44, false, null, 'leave.allowance.error.amount'],
            [ScheduleScope::Tenant, null, 2026, 40, false, 'A long enough note', 'leave.allowance.error.legal_minimum'],
            [ScheduleScope::Tenant, null, 2026, 40, true, 'short', 'leave.allowance.error.legal_minimum'],
        ] as [$scope, $target, $year, $amount, $equivalentMark, $note, $key]) {
            try {
                AllowancePolicy::create(Fx::uuid(9), $vacation, $scope, $target, $year, $amount, $equivalentMark, $note, Fx::at('2026-01-01'), null);
                self::fail($key);
            } catch (LeaveRefusedException $e) {
                self::assertSame($key, $e->key);
            }
        }
    }

    public function testAnAllowanceLine(): void
    {
        $type = LeaveType::create(Fx::uuid(1), 't1', new LeaveTypeInput('Vacation', source: AllowanceSource::Allowance), 'en', Fx::at('2026-01-01'));
        $line = AllowanceLine::create(Fx::uuid(2), $type, new WorkerRef('ana'), 2026, AllowanceLineKind::CarryOver, 4, " From 2025\u{0007} ", new LocalDate('2026-03-31'), Fx::at('2026-01-02 09:00:00'), new ActorRef('hr'));

        self::assertSame(Fx::uuid(2), $line->getId());
        self::assertSame('t1', $line->getTenantId());
        self::assertSame('ana', $line->getWorkerId());
        self::assertSame(Fx::uuid(1), $line->getLeaveTypeId());
        self::assertSame(2026, $line->getYear());
        self::assertSame(AllowanceLineKind::CarryOver, $line->getKind());
        self::assertSame(4, $line->getAmount());
        self::assertSame('From 2025', $line->getReason());
        self::assertSame('2026-03-31', $line->expiresOn()?->value);
        self::assertSame('hr', $line->getCreatedById());
        self::assertSame('09:00', $line->getCreatedAt()->format('H:i'));
        self::assertTrue($line->countsOn(new LocalDate('2026-03-31')));
        self::assertFalse($line->countsOn(new LocalDate('2026-04-01')));
        $line->expire(Fx::at('2026-04-01 01:00:00'));
        $line->expire(Fx::at('2026-04-02 01:00:00'));
        self::assertSame('2026-04-01', $line->getExpiredAt()?->format('Y-m-d'));
        self::assertFalse($line->countsOn(new LocalDate('2026-01-01')));
        foreach ([
            [AllowanceLineKind::Adjustment, 0, 'Reason', 2026, 'leave.allowance.error.line_amount'],
            [AllowanceLineKind::CarryOver, -2, 'Reason', 2026, 'leave.allowance.error.line_amount'],
            [AllowanceLineKind::Adjustment, 2, 'Reason', 1999, 'leave.allowance.error.line_amount'],
            [AllowanceLineKind::Adjustment, 2, 'ab', 2026, 'leave.allowance.error.reason'],
            [AllowanceLineKind::Adjustment, 2, str_repeat('r', 256), 2026, 'leave.allowance.error.reason'],
        ] as [$kind, $amount, $reason, $year, $key]) {
            try {
                AllowanceLine::create(Fx::uuid(3), $type, new WorkerRef('ana'), $year, $kind, $amount, $reason, null, Fx::at('2026-01-01'), null);
                self::fail($key);
            } catch (LeaveRefusedException $e) {
                self::assertSame($key, $e->key);
            }
        }
        self::assertNull(AllowanceLine::create(Fx::uuid(4), $type, new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, -3, 'Taken off', null, Fx::at('2026-01-01'), null)->expiresOn());
    }

    public function testTheSettings(): void
    {
        $settings = LeaveSettings::defaults('t1', Fx::at('2026-01-01'));
        self::assertFalse($settings->isEnabled());
        self::assertSame(RoundingMode::Nearest, $settings->rounding());
        self::assertSame(18, $settings->protectedCarryMonths());
        self::assertTrue($settings->colleaguesSeeAbsences());
        self::assertFalse($settings->medicalDocuments());
        self::assertSame(12, $settings->attachmentRetentionMonths());
        self::assertSame(100, $settings->overtimeRatio());
        self::assertNull($settings->planningDeadline());
        self::assertSame([], $settings->approvalChains());
        self::assertSame([], $settings->minStaffing());
        self::assertSame(1, $settings->getVersion());
        self::assertNull($settings->getUpdatedById());
        $settings->change(new LeaveSettingsInput(true, RoundingMode::Down, 24, false, true, 6, 150, '04-30'), new ActorRef('admin'), Fx::at('2026-02-01 10:00:00'));
        self::assertSame('t1', $settings->getTenantId());
        self::assertEquals(new LeaveSettingsInput(true, RoundingMode::Down, 24, false, true, 6, 150, '04-30'), $settings->input());
        self::assertSame('admin', $settings->getUpdatedById());
        self::assertSame('2026-02-01 10:00', $settings->getUpdatedAt()->format('Y-m-d H:i'));
        foreach ([
            [0, 12, 100, null, 'leave.settings.error.protected_months'],
            [61, 12, 100, null, 'leave.settings.error.protected_months'],
            [18, 0, 100, null, 'leave.settings.error.attachment_months'],
            [18, 49, 100, null, 'leave.settings.error.attachment_months'],
            [18, 12, 0, null, 'leave.settings.error.overtime_ratio'],
            [18, 12, 401, null, 'leave.settings.error.overtime_ratio'],
            [18, 12, 100, '13-01', 'leave.settings.error.planning_deadline'],
            [18, 12, 100, 'soon', 'leave.settings.error.planning_deadline'],
        ] as [$months, $attachments, $ratio, $deadline, $key]) {
            try {
                new LeaveSettingsInput(true, RoundingMode::Nearest, $months, true, false, $attachments, $ratio, $deadline);
                self::fail($key);
            } catch (LeaveRefusedException $e) {
                self::assertSame($key, $e->key);
                self::assertSame([], $e->params);
            }
        }
    }
}
