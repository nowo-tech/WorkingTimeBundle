<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\DayCount;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\DayPart;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function array_intersect_key;
use function array_sum;
use function intdiv;
use function ksort;
use function min;
use function preg_match;

/**
 * What a date range costs a worker (contract section 9.3; spec 204 FR-009; Beacon's `DayCounter`):
 *
 * - `natural_days`: every date counts (a whole day = 2 half-days, a half-day request = 1);
 * - `working_days`: a date counts when the worker's schedule of that date (dated: a schedule change in the middle of
 *   the request is honoured) has working time and it is not one of the worker's holidays;
 * - `hours`: on each counted working day, the requested time range capped at the day's scheduled time.
 *
 * The worker's holidays are those of the work centre of each day's schedule row, else the tenant's default centre;
 * a tenant without any centre applies all its calendars. Four queries for any range (schedule rows, fallback,
 * holidays, centres). Stateless.
 */
final readonly class DayCounter
{
    /** A request never spans more than a year and a day (validation stops it first; this bounds the loop). */
    public const MAX_RANGE_DAYS = 366;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private ScheduleResolver $schedules,
        private HolidayCalendarReaderInterface $holidays,
        private TransactionRunnerInterface $runner,
    ) {
    }

    /**
     * The cost of the range.
     *
     * @throws LeaveRefusedException `leave.request.error.*` for an impossible range or part
     */
    public function count(RegisterSettings $settings, WorkerProfile $worker, LeaveUnit $unit, LocalDate $from, LocalDate $to, DayPart $part = DayPart::Full, ?string $startTime = null, ?string $endTime = null): DayCount
    {
        self::validate($unit, $from, $to, $part, $startTime, $endTime);

        return $this->runner->read(function () use ($settings, $worker, $unit, $from, $to, $part, $startTime, $endTime): DayCount { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $days     = $this->schedules->rangeWithCentres($settings, $worker, $from, $to);
            $centreOn = [];
            $schedule = [];
            foreach ($days as $date => $day) {
                $centreOn[$date] = $day['centre'];
                $schedule[$date] = $day['schedule'];
            }

            return self::compute($unit, $schedule, $this->holidays->holidayMap(new TenantRef($settings->getTenantId()), $centreOn, $from, $to), $part, $startTime, $endTime);
        });
    }

    /**
     * The pure count (spec 204 SC-001 table cases): the days' schedules and the worker's holidays in, the cost out.
     *
     * @param array<string, DaySchedule> $days `Y-m-d` => the day's schedule, every date of the range
     * @param array<string, string> $holidays `Y-m-d` => name
     */
    public static function compute(LeaveUnit $unit, array $days, array $holidays, DayPart $part = DayPart::Full, ?string $startTime = null, ?string $endTime = null): DayCount
    {
        $requested = $part === DayPart::Hours && $startTime !== null && $endTime !== null ? ScheduleDefinition::minutes($endTime) - ScheduleDefinition::minutes($startTime) : 0;
        $counted   = [];
        foreach ($days as $date => $schedule) {
            $working = self::isWorkingDay($schedule) && !isset($holidays[$date]);
            $amount  = match ($unit) {
                LeaveUnit::NaturalDays => $part->isHalf() ? 1 : 2,
                LeaveUnit::WorkingDays => $working ? ($part->isHalf() ? 1 : 2) : 0,
                LeaveUnit::Hours       => $working ? min($requested, intdiv($schedule->expectedSeconds, 60)) : 0,
            };
            if ($amount > 0) {
                $counted[(string) $date] = $amount;
            }
        }
        $inRange = array_intersect_key($holidays, $days);
        ksort($inRange);

        return new DayCount($unit, array_sum($counted), $counted, $inRange);
    }

    /** A day with scheduled time (a daily target alone also makes it a working day). */
    public static function isWorkingDay(DaySchedule $schedule): bool
    {
        return $schedule->ranges !== [] || $schedule->expectedSeconds > 0;
    }

    /**
     * Refuses an impossible range or part.
     *
     * @throws LeaveRefusedException `leave.request.error.*`
     */
    public static function validate(LeaveUnit $unit, LocalDate $from, LocalDate $to, DayPart $part, ?string $startTime, ?string $endTime): void
    {
        if ($to->compare($from) < 0) {
            throw new LeaveRefusedException('leave.request.error.end_before_start');
        }
        if ((int) $from->toDateTime()->diff($to->toDateTime())->days >= self::MAX_RANGE_DAYS) {
            throw new LeaveRefusedException('leave.request.error.too_long');
        }
        if ($part->isHalf() && !$from->equals($to)) {
            throw new LeaveRefusedException('leave.request.error.half_day_range');
        }
        if (($unit === LeaveUnit::Hours) !== ($part === DayPart::Hours)) {
            throw new LeaveRefusedException('leave.request.error.part');
        }
        if ($part === DayPart::Hours) {
            $valid = static fn (?string $time): bool => $time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) === 1;
            if (!$valid($startTime) || !$valid($endTime) || (string) $endTime <= (string) $startTime) {
                throw new LeaveRefusedException('leave.request.error.times');
            }
        }
    }
}
