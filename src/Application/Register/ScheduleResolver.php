<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\ScheduleFallbackProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function in_array;

/**
 * The schedule a worker's day is measured against (spec 198 FR-003 / FR-004): the dated row valid on that date for the
 * worker, else for one of their groups, else the tenant default; without any row, the fallback port's schedule
 * (Beacon: the organization's working hours, then the instance default), else no schedule in the default zone.
 * Thresholds and break rules come from the tenant's settings. Stateless.
 */
final readonly class ScheduleResolver
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterStoreInterface $store,
        private ScheduleFallbackProviderInterface $fallback,
        private string $defaultTimezone = 'UTC',
    ) {
    }

    /**
     * The worker's local date at an instant and that day's schedule (the time zone comes with the schedule: resolve
     * on the UTC date, again when the local date differs).
     *
     * @return array{LocalDate, DaySchedule}
     */
    public function at(RegisterSettings $settings, WorkerProfile $worker, DateTimeImmutable $at): array
    {
        $utc   = LocalDate::of($at, new DateTimeZone('UTC'));
        $first = $this->forDate($settings, $worker, $utc);
        $local = LocalDate::of($at, $first->zone());

        return [$local, $local->equals($utc) ? $first : $this->forDate($settings, $worker, $local)];
    }

    /**
     * The worker's schedule snapshot of a date.
     */
    public function forDate(RegisterSettings $settings, WorkerProfile $worker, LocalDate $date): DaySchedule
    {
        $tenant = new TenantRef($settings->getTenantId());

        return self::day($settings, $this->definition($tenant, $worker, $date, $this->store->scheduleCandidates($tenant, $date)), $date);
    }

    /**
     * Each date's schedule over a range in two queries (the rows valid by the end, and the fallback once).
     *
     * @return array<string, DaySchedule> `Y-m-d` => schedule
     */
    public function range(RegisterSettings $settings, WorkerProfile $worker, LocalDate $from, LocalDate $to): array
    {
        $tenant     = new TenantRef($settings->getTenantId());
        $candidates = $this->store->scheduleCandidates($tenant, $to);
        $days       = [];
        for ($date = $from; $date->compare($to) <= 0; $date = $date->plusDays(1)) {
            $valid = [];
            foreach ($candidates as $candidate) {
                if ($candidate->validFrom()->compare($date) <= 0) {
                    $valid[] = $candidate;
                }
            }
            $days[$date->value] = self::day($settings, $this->definition($tenant, $worker, $date, $valid), $date);
        }

        return $days;
    }

    /**
     * The most specific row among the candidates (newest validity first).
     *
     * @param list<ScheduleAssignment> $candidates
     */
    public static function choose(array $candidates, WorkerProfile $worker): ?ScheduleAssignment
    {
        $groups = [];
        foreach ($worker->groups as $group) {
            $groups[] = $group->value;
        }
        foreach ([ScheduleScope::Worker, ScheduleScope::Group, ScheduleScope::Tenant] as $scope) {
            foreach ($candidates as $candidate) {
                if ($candidate->getScope() !== $scope) {
                    continue;
                }
                $target = $candidate->getTargetId();
                $match  = match ($scope) {
                    ScheduleScope::Worker => $target === $worker->worker->value,
                    ScheduleScope::Group  => $target !== null && in_array($target, $groups, true),
                    ScheduleScope::Tenant => true,
                };
                if ($match) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * The day snapshot of a weekly schedule on a date, with the tenant's thresholds and break rules.
     */
    public static function day(RegisterSettings $settings, ScheduleDefinition $definition, LocalDate $date): DaySchedule
    {
        $weekday = $date->weekday();
        $weekly  = $settings->weeklyOvertimeMinutes() ?? $definition->weeklyTargetMinutes;

        return new DaySchedule(
            $definition->timezone,
            $definition->rangesOn($weekday),
            $definition->expectedMinutesOn($weekday) * 60,
            $settings->dailyOvertimeMinutes() === null ? null : $settings->dailyOvertimeMinutes() * 60,
            $weekly === null ? null : $weekly * 60,
            $definition->flexMinutes > 0 ? $definition->flexMinutes : $settings->flexMinutes(),
            $settings->breaksCounted(),
            $settings->minBreakMinutes(),
        );
    }

    /**
     * @param list<ScheduleAssignment> $candidates
     */
    private function definition(TenantRef $tenant, WorkerProfile $worker, LocalDate $date, array $candidates): ScheduleDefinition
    {
        return self::choose($candidates, $worker)?->definition()
            ?? $this->fallback->fallback($tenant, $date)
            ?? new ScheduleDefinition($this->defaultTimezone);
    }
}
