<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;

use function array_sum;
use function intdiv;
use function max;
use function sprintf;

/**
 * The totals of a run of days (spec 198 FR-010 / FR-021 / FR-023): worked, expected, daily overtime, weekly overtime
 * (each ISO week's worked time above its threshold), days worked, missing clock-outs, corrections, breaks.
 */
final readonly class MonthSummary
{
    /**
     * @param array<string, int> $weeklyOvertime ISO week (`2026-W45`) => seconds above the threshold
     */
    public function __construct(
        public int $workedSeconds,
        public int $expectedSeconds,
        public int $dailyOvertimeSeconds,
        public array $weeklyOvertime,
        public int $daysWorked,
        public int $missingClockOuts,
        public int $corrections,
        public int $breakSeconds,
    ) {
    }

    /**
     * @param array<string, DayRecord> $days `Y-m-d` => record
     */
    public static function of(array $days): self
    {
        $worked      = 0;
        $expected    = 0;
        $overtime    = 0;
        $breaks      = 0;
        $daysWorked  = 0;
        $missing     = 0;
        $corrections = 0;
        /** @var array<string, array{worked: int, threshold: ?int}> $weeks */
        $weeks = [];
        foreach ($days as $record) {
            $schedule = $record->workday->schedule();
            $worked += $record->workedSeconds;
            $breaks += $record->breakSeconds;
            $expected += $schedule->expectedSeconds;
            $overtime += $record->overtimeSeconds();
            $daysWorked += $record->workSeconds > 0 ? 1 : 0;
            $missing += $record->has(DayFlag::MissingClockOut) ? 1 : 0;
            foreach ($record->revisions as $revision) {
                $corrections += $revision->getKind()->isCorrection() ? 1 : 0;
            }
            $week = $record->workday->date()->toDateTime()->format('o-\WW');
            $weeks[$week] ??= ['worked' => 0, 'threshold' => null];
            $weeks[$week]['worked'] += $record->workedSeconds;
            $weeks[$week]['threshold'] = $schedule->weeklyOvertimeSeconds ?? $weeks[$week]['threshold'];
        }
        $weekly = [];
        foreach ($weeks as $week => $sums) {
            if ($sums['threshold'] !== null && $sums['worked'] > $sums['threshold']) {
                $weekly[$week] = $sums['worked'] - $sums['threshold'];
            }
        }

        return new self($worked, $expected, $overtime, $weekly, $daysWorked, $missing, $corrections, $breaks);
    }

    /** Sum of the weekly overtime. */
    public function weeklyOvertimeSeconds(): int
    {
        return array_sum($this->weeklyOvertime);
    }

    /** `7:05` (hours and minutes; never negative). */
    public static function hours(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
