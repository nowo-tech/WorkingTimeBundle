<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Entitlement;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function in_array;
use function sprintf;

/**
 * A worker's yearly entitlement of a type (contract section 9.3; spec 204 FR-005 – FR-007; Beacon's
 * `AllowanceResolver`). Pure: the caller passes the year's policies and the worker's lines it read in the call (worker
 * mode: no memo across calls, nothing to reset between write-unit attempts).
 *
 * 1. The policy: the worker's own row, else one of their groups' (newest first), else the tenant default of that year.
 * 2. Part-time: hour-unit allowances × the worker's `contractRatio` (a worker's own row is taken as it is); day units
 *    keep full days (the counted days follow the worker's schedule).
 * 3. Pro-rata: × the natural days employed that year (from `joinedOn`, until `leftOn`) ÷ the days of the year, rounded
 *    per the tenant's setting (nearest / up / down half-day or minute); a worker's own row is taken as it is.
 *    Recomputed on every read, so a change of dates applies at once; taken days are request rows and never change.
 * 4. Lines: adjustments, carry-overs until their expiry and protected carry-overs, summed on the day of reading.
 */
final class AllowanceResolver
{
    /**
     * The entitlement.
     *
     * @param list<AllowancePolicy> $policies the tenant's policies of the year, newest first
     * @param list<AllowanceLine> $lines the worker's lines of the year
     */
    public static function entitlement(RoundingMode $rounding, LeaveType $type, WorkerProfile $worker, int $year, array $policies, array $lines, LocalDate $today): Entitlement
    {
        $policy   = self::choose($policies, $type->getId(), $worker);
        $own      = $policy?->getScope() === ScheduleScope::Worker;
        $partTime = $policy instanceof AllowancePolicy && !$own && $type->getUnit() === LeaveUnit::Hours ? ($worker->contractRatio ?? 1.0) : 1.0;
        $proRata  = $policy instanceof AllowancePolicy && !$own ? self::proRata($year, $worker->joinedOn, $worker->leftOn) : 1.0;
        $base     = match (true) {
            !$policy instanceof AllowancePolicy   => 0,
            $proRata === 1.0 && $partTime === 1.0 => $policy->getAmount(),
            default                               => $rounding->apply($policy->getAmount() * $partTime * $proRata),
        };
        $mine  = [];
        $total = 0;
        foreach ($lines as $line) {
            if ($line->getWorkerId() !== $worker->worker->value || $line->getLeaveTypeId() !== $type->getId() || $line->getYear() !== $year) {
                continue;
            }
            $mine[] = $line;
            $total += $line->countsOn($today) ? $line->getAmount() : 0;
        }

        return new Entitlement($policy, $base, $total, $mine, $proRata, $partTime);
    }

    /**
     * The most specific policy of a type for a worker (worker → group → tenant; newest first within a scope).
     *
     * @param list<AllowancePolicy> $policies newest first
     */
    public static function choose(array $policies, string $typeId, WorkerProfile $worker): ?AllowancePolicy
    {
        $groups = [];
        foreach ($worker->groups as $group) {
            $groups[] = $group->value;
        }
        foreach ([ScheduleScope::Worker, ScheduleScope::Group, ScheduleScope::Tenant] as $scope) {
            foreach ($policies as $policy) {
                if ($policy->getLeaveTypeId() !== $typeId || $policy->getScope() !== $scope) {
                    continue;
                }
                $applies = match ($scope) {
                    ScheduleScope::Worker => $policy->getTargetId() === $worker->worker->value,
                    ScheduleScope::Group  => in_array($policy->getTargetId(), $groups, true),
                    ScheduleScope::Tenant => true,
                };
                if ($applies) {
                    return $policy;
                }
            }
        }

        return null;
    }

    /**
     * The share of the year employed: from the join date (or 1 January) to the leaving date (or 31 December), in
     * natural days; 1.0 for the whole year, 0.0 outside it.
     */
    public static function proRata(int $year, ?LocalDate $joinedOn, ?LocalDate $leftOn): float
    {
        $first = new LocalDate(sprintf('%04d-01-01', $year));
        $last  = new LocalDate(sprintf('%04d-12-31', $year));
        $from  = $joinedOn instanceof LocalDate && $joinedOn->compare($first) > 0 ? $joinedOn : $first;
        $to    = $leftOn instanceof LocalDate && $leftOn->compare($last) < 0 ? $leftOn : $last;
        if ($from->compare($last) > 0 || $to->compare($first) < 0 || $to->compare($from) < 0) {
            return 0.0;
        }
        $days     = self::days($first, $last);
        $employed = self::days($from, $to);

        return $employed === $days ? 1.0 : $employed / $days;
    }

    /** Natural days from one date to another, both included. */
    private static function days(LocalDate $from, LocalDate $to): int
    {
        return (int) $from->toDateTime()->diff($to->toDateTime())->days + 1;
    }
}
