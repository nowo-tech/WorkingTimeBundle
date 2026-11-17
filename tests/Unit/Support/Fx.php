<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function sprintf;

/**
 * Small object factory shared by the unit tests.
 */
final class Fx
{
    public static function uuid(int $n): string
    {
        return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
    }

    public static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /**
     * @param list<array{from: string, to: string}>|null $ranges
     */
    public static function schedule(?array $ranges = null, int $expected = 28_800, int $flex = 0, int $minBreak = 0, bool $breaksCounted = false, ?int $daily = null, ?int $weekly = null): DaySchedule
    {
        return new DaySchedule('UTC', $ranges ?? [['from' => '09:00', 'to' => '17:00']], $expected, $daily, $weekly, $flex, $breaksCounted, $minBreak);
    }

    public static function workday(?DaySchedule $schedule = null, string $date = '2026-11-10', int $n = 1): Workday
    {
        return Workday::open(self::uuid($n), new TenantRef('t1'), new WorkerRef('w1'), 'Ana', new LocalDate($date), $schedule ?? self::schedule(), self::at('2026-11-10 07:00:00'));
    }
}
