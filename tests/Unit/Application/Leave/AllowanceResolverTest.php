<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\AllowanceResolver;
use Nowo\WorkingTimeBundle\Application\Leave\NoLeaveUsage;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;

/**
 * Spec 204 FR-005 – FR-007: worker → group → tenant policies, pro-rata by natural days employed (join and leaving
 * dates) with the tenant's rounding, part-time hour allowances × the contract ratio, and the lines counting on the day
 * of reading (expired carry-overs out).
 */
final class AllowanceResolverTest extends TestCase
{
    public function testTheMostSpecificPolicyWins(): void
    {
        $type   = $this->type(LeaveUnit::WorkingDays);
        $tenant = $this->policy(1, $type, ScheduleScope::Tenant, null, 44);
        $group  = $this->policy(2, $type, ScheduleScope::Group, 'g1', 46);
        $own    = $this->policy(3, $type, ScheduleScope::Worker, 'ana', 50);
        $other  = $this->policy(4, $this->type(LeaveUnit::WorkingDays, 9), ScheduleScope::Tenant, null, 10);
        $ana    = $this->worker(groups: ['g1']);

        self::assertSame($own, AllowanceResolver::choose([$other, $own, $group, $tenant], $type->getId(), $ana));
        self::assertSame($group, AllowanceResolver::choose([$other, $group, $tenant], $type->getId(), $ana));
        self::assertSame($tenant, AllowanceResolver::choose([$group, $tenant], $type->getId(), $this->worker()));
        self::assertNull(AllowanceResolver::choose([$own], $type->getId(), $this->worker('bea')));
        self::assertNull(AllowanceResolver::choose([], $type->getId(), $ana));
    }

    public function testProRataByNaturalDaysEmployed(): void
    {
        self::assertSame(1.0, AllowanceResolver::proRata(2026, null, null));
        self::assertSame(1.0, AllowanceResolver::proRata(2026, new LocalDate('2025-06-01'), new LocalDate('2027-01-01')));
        self::assertEqualsWithDelta(184 / 365, AllowanceResolver::proRata(2026, new LocalDate('2026-07-01'), null), 1e-9);
        self::assertEqualsWithDelta(181 / 365, AllowanceResolver::proRata(2026, null, new LocalDate('2026-06-30')), 1e-9);
        self::assertEqualsWithDelta(366 / 366, AllowanceResolver::proRata(2028, null, null), 1e-9);
        self::assertSame(0.0, AllowanceResolver::proRata(2026, new LocalDate('2027-01-01'), null));
        self::assertSame(0.0, AllowanceResolver::proRata(2026, null, new LocalDate('2025-12-31')));
        self::assertSame(0.0, AllowanceResolver::proRata(2026, new LocalDate('2026-09-01'), new LocalDate('2026-08-01')));
    }

    public function testAJoinerGetsTheRoundedShare(): void
    {
        $type     = $this->type(LeaveUnit::WorkingDays);
        $policies = [$this->policy(1, $type, ScheduleScope::Tenant, null, 44)];
        $joiner   = $this->worker(joined: '2026-07-01');
        $today    = new LocalDate('2026-11-18');

        // 44 × 184 / 365 = 22.18 half-days.
        self::assertSame(22, AllowanceResolver::entitlement(RoundingMode::Nearest, $type, $joiner, 2026, $policies, [], $today)->base);
        self::assertSame(23, AllowanceResolver::entitlement(RoundingMode::Up, $type, $joiner, 2026, $policies, [], $today)->base);
        self::assertSame(22, AllowanceResolver::entitlement(RoundingMode::Down, $type, $joiner, 2026, $policies, [], $today)->base);
        $whole = AllowanceResolver::entitlement(RoundingMode::Up, $type, $this->worker(), 2026, $policies, [], $today);
        self::assertSame(44, $whole->total());
        self::assertSame(1.0, $whole->proRata);
        $none = AllowanceResolver::entitlement(RoundingMode::Nearest, $type, $this->worker(), 2026, [], [], $today);
        self::assertSame(0, $none->total());
        self::assertNull($none->policy);
    }

    public function testAWorkersOwnRowIsTakenAsItIs(): void
    {
        $type   = $this->type(LeaveUnit::Hours);
        $own    = [$this->policy(1, $type, ScheduleScope::Worker, 'ana', 6000)];
        $worker = $this->worker(joined: '2026-07-01', ratio: 0.5);
        $result = AllowanceResolver::entitlement(RoundingMode::Nearest, $type, $worker, 2026, $own, [], new LocalDate('2026-11-18'));

        self::assertSame(6000, $result->base);
        self::assertSame(1.0, $result->partTime);
    }

    public function testHourAllowancesFollowTheContractRatio(): void
    {
        $hours  = $this->type(LeaveUnit::Hours);
        $days   = $this->type(LeaveUnit::WorkingDays, 2);
        $part   = $this->worker(ratio: 0.5);
        $today  = new LocalDate('2026-11-18');
        $result = AllowanceResolver::entitlement(RoundingMode::Nearest, $hours, $part, 2026, [$this->policy(1, $hours, ScheduleScope::Tenant, null, 1000)], [], $today);

        self::assertSame(500, $result->base);
        self::assertSame(0.5, $result->partTime);
        self::assertSame(44, AllowanceResolver::entitlement(RoundingMode::Nearest, $days, $part, 2026, [$this->policy(2, $days, ScheduleScope::Tenant, null, 44)], [], $today)->base, 'day units keep full days');
    }

    public function testLinesCountUntilTheyExpire(): void
    {
        $type  = $this->type(LeaveUnit::WorkingDays, carry: true);
        $ana   = $this->worker();
        $lines = [
            AllowanceLine::create(Fx::uuid(11), $type, new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, -2, 'Correction', null, Fx::at('2026-01-02'), null),
            AllowanceLine::create(Fx::uuid(12), $type, new WorkerRef('ana'), 2026, AllowanceLineKind::CarryOver, 6, 'From 2025', new LocalDate('2026-03-31'), Fx::at('2026-01-02'), null),
            AllowanceLine::create(Fx::uuid(13), $type, new WorkerRef('ana'), 2026, AllowanceLineKind::ProtectedCarryOver, 4, 'Birth leave', new LocalDate('2027-06-30'), Fx::at('2026-01-02'), null),
            AllowanceLine::create(Fx::uuid(14), $type, new WorkerRef('bea'), 2026, AllowanceLineKind::Adjustment, 9, 'Someone else', null, Fx::at('2026-01-02'), null),
            AllowanceLine::create(Fx::uuid(15), $type, new WorkerRef('ana'), 2025, AllowanceLineKind::Adjustment, 9, 'Other year', null, Fx::at('2026-01-02'), null),
        ];
        $policy = [$this->policy(1, $type, ScheduleScope::Tenant, null, 44)];

        $march = AllowanceResolver::entitlement(RoundingMode::Nearest, $type, $ana, 2026, $policy, $lines, new LocalDate('2026-03-31'));
        self::assertSame(44 - 2 + 6 + 4, $march->total());
        self::assertCount(3, $march->lines);
        $april = AllowanceResolver::entitlement(RoundingMode::Nearest, $type, $ana, 2026, $policy, $lines, new LocalDate('2026-04-01'));
        self::assertSame(44 - 2 + 4, $april->total(), 'the carry-over stops counting the day after its expiry');
        $lines[2]->expire(Fx::at('2026-04-01'));
        self::assertSame(44 - 2 + 6, AllowanceResolver::entitlement(RoundingMode::Nearest, $type, $ana, 2026, $policy, $lines, new LocalDate('2026-03-01'))->total(), 'an expired line no longer counts');
        self::assertSame([], (new NoLeaveUsage())->usage(new TenantRef('t1'), new WorkerRef('ana'), 2026, new LocalDate('2026-01-01')));
    }

    private function type(LeaveUnit $unit, int $n = 1, bool $carry = false): LeaveType
    {
        return LeaveType::create(Fx::uuid(100 + $n), 't1', new LeaveTypeInput('Vacation', true, AllowanceSource::Allowance, $unit, carryOver: $carry), 'en', Fx::at('2026-01-01'), $n === 1 ? 'vacation' : null);
    }

    private function policy(int $n, LeaveType $type, ScheduleScope $scope, ?string $target, int $amount): AllowancePolicy
    {
        return AllowancePolicy::create(Fx::uuid($n), $type, $scope, $target, 2026, $amount, true, 'Agreement article 12', Fx::at('2026-01-01'), null);
    }

    /**
     * @param list<string> $groups
     */
    private function worker(string $ref = 'ana', array $groups = [], ?string $joined = null, ?float $ratio = null): WorkerProfile
    {
        $refs = [];
        foreach ($groups as $group) {
            $refs[] = new GroupRef($group);
        }

        return new WorkerProfile(new WorkerRef($ref), 'Ana', null, 'en', true, $joined === null ? null : new LocalDate($joined), null, $refs, $ratio);
    }
}
