<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Notice;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

use function intdiv;
use function sprintf;

/**
 * The recipient's hours (spec 198 FR-025): notices and reminders go out only inside the schedule ± the window
 * (15 minutes by default).
 */
final class HoursWindow
{
    /**
     * The first instant at or after `$now` inside the schedule ± window, over the given days (in date order); `$now`
     * when no day has ranges (no schedule: no restriction).
     *
     * @param array<string, DaySchedule> $days `Y-m-d` => schedule
     */
    public static function nextDeliverable(array $days, DateTimeImmutable $now, int $windowMinutes): DateTimeImmutable
    {
        $any = false;
        foreach ($days as $date => $schedule) {
            foreach ($schedule->ranges as $range) {
                $any           = true;
                [$start, $end] = self::bounds(new LocalDate($date), $schedule, $range, $windowMinutes);
                if ($now <= $end) {
                    return $now >= $start ? $now : $start;
                }
            }
        }

        return $any ? $now->modify('+1 day') : $now;
    }

    /**
     * Whether `$now` is inside one of the day's ranges ± window.
     */
    public static function inside(LocalDate $date, DaySchedule $schedule, DateTimeImmutable $now, int $windowMinutes): bool
    {
        foreach ($schedule->ranges as $range) {
            [$start, $end] = self::bounds($date, $schedule, $range, $windowMinutes);
            if ($now >= $start && $now <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$now` is within ± window of the day's scheduled start (reminder to clock in) or end (to clock out).
     *
     * @return 'in'|'out'|null
     */
    public static function reminder(LocalDate $date, DaySchedule $schedule, DateTimeImmutable $now, int $windowMinutes): ?string
    {
        $start = $schedule->start($date);
        $end   = $schedule->end($date);
        $span  = $windowMinutes * 60;
        if ($start instanceof DateTimeImmutable && $now->getTimestamp() >= $start->getTimestamp() - $span && $now->getTimestamp() <= $start->getTimestamp() + $span) {
            return 'in';
        }
        if ($end instanceof DateTimeImmutable && $now->getTimestamp() >= $end->getTimestamp() - $span && $now->getTimestamp() <= $end->getTimestamp() + $span) {
            return 'out';
        }

        return null;
    }

    /**
     * @param array{from: string, to: string} $range
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function bounds(LocalDate $date, DaySchedule $schedule, array $range, int $windowMinutes): array
    {
        $midnight = $date->toDateTime($schedule->zone());
        $from     = ScheduleDefinition::minutes($range['from']);
        $to       = ScheduleDefinition::minutes($range['to']);

        return [
            $midnight->setTime(intdiv($from, 60), $from % 60)->modify(sprintf('-%d minutes', $windowMinutes)),
            $midnight->setTime(intdiv($to, 60), $to % 60)->modify(sprintf('+%d minutes', $windowMinutes)),
        ];
    }
}
