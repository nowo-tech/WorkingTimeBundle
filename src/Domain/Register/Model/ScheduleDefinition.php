<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeZone;
use InvalidArgumentException;

use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function usort;

/**
 * A weekly working schedule (contract section 5 `DaySchedule` input; Beacon `ScheduleAssignment` + `WorkingHours`):
 * an IANA time zone, time ranges per ISO weekday (`HH:MM`–`HH:MM`, no overlap on a day), optional daily / weekly
 * target minutes (a flexible schedule: at most 720 a day) and the flexible band around the first start.
 */
final readonly class ScheduleDefinition
{
    /** Longest daily target (12 h). */
    public const MAX_DAY_MINUTES = 720;

    /** Longest flexible band. */
    public const MAX_FLEX_MINUTES = 240;

    /** @var list<array{day: int, from: string, to: string}> by day, then start */
    public array $slots;

    /**
     * @param iterable<array{day: int, from: string, to: string}> $slots
     *
     * @throws InvalidArgumentException on an unknown zone, a bad range, an overlap or a target out of range
     */
    public function __construct(
        public string $timezone,
        iterable $slots = [],
        public ?int $dailyTargetMinutes = null,
        public ?int $weeklyTargetMinutes = null,
        public int $flexMinutes = 0,
    ) {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an IANA time zone.', $timezone));
        }
        $list = [];
        foreach ($slots as $slot) {
            if ($slot['day'] < 1 || $slot['day'] > 7) {
                throw new InvalidArgumentException('A schedule slot day is 1 (Monday) to 7 (Sunday).');
            }
            if (self::minutes($slot['from']) >= self::minutes($slot['to'])) {
                throw new InvalidArgumentException('A schedule slot ends after it starts.');
            }
            $list[] = ['day' => $slot['day'], 'from' => $slot['from'], 'to' => $slot['to']];
        }
        usort($list, static fn (array $a, array $b): int => [$a['day'], $a['from']] <=> [$b['day'], $b['from']]);
        for ($i = 1, $n = count($list); $i < $n; ++$i) {
            if ($list[$i]['day'] === $list[$i - 1]['day'] && self::minutes($list[$i]['from']) < self::minutes($list[$i - 1]['to'])) {
                throw new InvalidArgumentException('Schedule slots of a day may not overlap.');
            }
        }
        if ($dailyTargetMinutes !== null && ($dailyTargetMinutes < 0 || $dailyTargetMinutes > self::MAX_DAY_MINUTES)) {
            throw new InvalidArgumentException('The daily target is 0-720 minutes.');
        }
        if ($weeklyTargetMinutes !== null && ($weeklyTargetMinutes < 0 || $weeklyTargetMinutes > 7 * self::MAX_DAY_MINUTES)) {
            throw new InvalidArgumentException('The weekly target is 0-5040 minutes.');
        }
        if ($flexMinutes < 0 || $flexMinutes > self::MAX_FLEX_MINUTES) {
            throw new InvalidArgumentException('The flexible band is 0-240 minutes.');
        }
        $this->slots = $list;
    }

    /**
     * Minutes since midnight of `HH:MM` (`24:00` allowed as an end).
     *
     * @throws InvalidArgumentException
     */
    public static function minutes(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$|^24:00$/D', $time) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not HH:MM.', $time));
        }
        [$hour, $minute] = explode(':', $time);

        return (int) $hour * 60 + (int) $minute;
    }

    /**
     * The ranges of an ISO weekday.
     *
     * @return list<array{from: string, to: string}>
     */
    public function rangesOn(int $weekday): array
    {
        $ranges = [];
        foreach ($this->slots as $slot) {
            if ($slot['day'] === $weekday) {
                $ranges[] = ['from' => $slot['from'], 'to' => $slot['to']];
            }
        }

        return $ranges;
    }

    /**
     * Expected minutes of an ISO weekday: the daily target on a day with ranges (or every weekday Monday–Friday without
     * any range), else the scheduled minutes.
     */
    public function expectedMinutesOn(int $weekday): int
    {
        $scheduled = 0;
        foreach ($this->rangesOn($weekday) as $range) {
            $scheduled += self::minutes($range['to']) - self::minutes($range['from']);
        }
        if ($this->dailyTargetMinutes === null) {
            return $scheduled;
        }
        $working = $this->slots === [] ? $weekday <= 5 : $scheduled > 0;

        return $working ? $this->dailyTargetMinutes : 0;
    }

    /**
     * Stored form (JSON column).
     *
     * @return array{timezone: string, slots: list<array{day: int, from: string, to: string}>, daily_target_minutes: ?int, weekly_target_minutes: ?int, flex_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'timezone'              => $this->timezone,
            'slots'                 => $this->slots,
            'daily_target_minutes'  => $this->dailyTargetMinutes,
            'weekly_target_minutes' => $this->weeklyTargetMinutes,
            'flex_minutes'          => $this->flexMinutes,
        ];
    }

    /**
     * From the stored form or the configuration (`{timezone, ranges: {mon: ['09:00-14:00']}, ...}` is also accepted).
     *
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data, string $defaultZone = 'UTC'): self
    {
        $slots = [];
        foreach (is_array($data['slots'] ?? null) ? $data['slots'] : [] as $slot) {
            if (is_array($slot) && is_int($slot['day'] ?? null) && is_string($slot['from'] ?? null) && is_string($slot['to'] ?? null)) {
                $slots[] = ['day' => $slot['day'], 'from' => $slot['from'], 'to' => $slot['to']];
            }
        }
        $days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        foreach (is_array($data['ranges'] ?? null) ? $data['ranges'] : [] as $day => $ranges) {
            if (!isset($days[$day]) || !is_array($ranges)) {
                throw new InvalidArgumentException('Schedule ranges are keyed mon..sun.');
            }
            foreach ($ranges as $range) {
                if (!is_string($range) || preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/D', $range, $m) !== 1) {
                    throw new InvalidArgumentException('A schedule range is "HH:MM-HH:MM".');
                }
                $slots[] = ['day' => $days[$day], 'from' => $m[1], 'to' => $m[2]];
            }
        }
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            is_string($data['timezone'] ?? null) ? $data['timezone'] : $defaultZone,
            array_values($slots),
            $int('daily_target_minutes'),
            $int('weekly_target_minutes'),
            $int('flex_minutes') ?? 0,
        );
    }
}
