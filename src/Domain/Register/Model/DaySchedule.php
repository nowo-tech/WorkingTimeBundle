<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;

/**
 * What one worker's day is measured against (spec 198 FR-003 / FR-004): the time zone, the day's ranges, the expected
 * time, the overtime thresholds, the flexible band and the break rules. Resolved once when the workday is created and
 * stored with it, so a later schedule or settings change never alters past flags. The stored keys are Beacon's.
 */
final readonly class DaySchedule
{
    /**
     * @param list<array{from: string, to: string}> $ranges local `HH:MM`
     */
    public function __construct(
        public string $timezone,
        public array $ranges = [],
        public int $expectedSeconds = 0,
        public ?int $dailyOvertimeSeconds = null,
        public ?int $weeklyOvertimeSeconds = null,
        public int $flexMinutes = 0,
        public bool $breaksCounted = false,
        public int $minBreakMinutes = 0,
    ) {
    }

    /**
     * From the stored snapshot (an unknown zone reads as UTC: a stored record is never refused).
     *
     * @param array<mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $ranges = [];
        foreach (is_array($stored['ranges'] ?? null) ? $stored['ranges'] : [] as $range) {
            if (is_array($range) && is_string($range['from'] ?? null) && is_string($range['to'] ?? null)) {
                $ranges[] = ['from' => $range['from'], 'to' => $range['to']];
            }
        }
        $zone = is_string($stored['timezone'] ?? null) ? $stored['timezone'] : 'UTC';
        $int  = static fn (string $key): ?int => is_int($stored[$key] ?? null) ? $stored[$key] : null;

        return new self(
            in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC',
            $ranges,
            $int('expectedSeconds') ?? 0,
            $int('dailyOvertimeSeconds'),
            $int('weeklyOvertimeSeconds'),
            $int('flexMinutes') ?? 0,
            ($stored['breaksCounted'] ?? false) === true,
            $int('minBreakMinutes') ?? 0,
        );
    }

    /**
     * The stored snapshot.
     *
     * @return array{timezone: string, ranges: list<array{from: string, to: string}>, expectedSeconds: int, dailyOvertimeSeconds: ?int, weeklyOvertimeSeconds: ?int, flexMinutes: int, breaksCounted: bool, minBreakMinutes: int}
     */
    public function toArray(): array
    {
        return [
            'timezone'              => $this->timezone,
            'ranges'                => $this->ranges,
            'expectedSeconds'       => $this->expectedSeconds,
            'dailyOvertimeSeconds'  => $this->dailyOvertimeSeconds,
            'weeklyOvertimeSeconds' => $this->weeklyOvertimeSeconds,
            'flexMinutes'           => $this->flexMinutes,
            'breaksCounted'         => $this->breaksCounted,
            'minBreakMinutes'       => $this->minBreakMinutes,
        ];
    }

    /** The time zone. */
    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /** Whether any time is expected that day. */
    public function isWorkingDay(): bool
    {
        return $this->expectedSeconds > 0;
    }

    /** Expected minutes. */
    public function expectedMinutes(): int
    {
        return intdiv($this->expectedSeconds, 60);
    }

    /**
     * The daily overtime threshold: the setting, else the expected time (null = nothing expected, no flag).
     */
    public function dailyThreshold(): ?int
    {
        return $this->dailyOvertimeSeconds ?? ($this->expectedSeconds > 0 ? $this->expectedSeconds : null);
    }

    /** The scheduled start on the date (null without ranges). */
    public function start(LocalDate $date): ?DateTimeImmutable
    {
        return $this->ranges === [] ? null : $this->at($date, $this->ranges[0]['from']);
    }

    /** The scheduled end on the date (null without ranges). */
    public function end(LocalDate $date): ?DateTimeImmutable
    {
        return $this->ranges === [] ? null : $this->at($date, $this->ranges[count($this->ranges) - 1]['to']);
    }

    private function at(LocalDate $date, string $time): DateTimeImmutable
    {
        $minutes = ScheduleDefinition::minutes($time);

        return $date->toDateTime($this->zone())->setTime(intdiv($minutes, 60), $minutes % 60);
    }
}
