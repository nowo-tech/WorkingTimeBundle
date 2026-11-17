<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register\Model;

use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleDefinition (configured weekly schedule) and DaySchedule (frozen day snapshot).
 */
final class ScheduleTest extends TestCase
{
    public function testSlotsAreSortedAndQueried(): void
    {
        $definition = new ScheduleDefinition('Europe/Madrid', [
            ['day' => 2, 'from' => '15:00', 'to' => '18:00'],
            ['day' => 1, 'from' => '09:00', 'to' => '13:00'],
            ['day' => 2, 'from' => '09:00', 'to' => '13:00'],
        ]);

        self::assertSame(1, $definition->slots[0]['day']);
        self::assertSame([['from' => '09:00', 'to' => '13:00'], ['from' => '15:00', 'to' => '18:00']], $definition->rangesOn(2));
        self::assertSame([], $definition->rangesOn(3));
        self::assertSame(420, $definition->expectedMinutesOn(2));
    }

    public function testExpectedMinutesWithADailyTarget(): void
    {
        $noSlots = new ScheduleDefinition('UTC', [], 480);
        self::assertSame(480, $noSlots->expectedMinutesOn(5));
        self::assertSame(0, $noSlots->expectedMinutesOn(6));

        $withSlots = new ScheduleDefinition('UTC', [['day' => 1, 'from' => '09:00', 'to' => '10:00']], 480);
        self::assertSame(480, $withSlots->expectedMinutesOn(1));
        self::assertSame(0, $withSlots->expectedMinutesOn(2));
    }

    public function testToArrayRoundTrips(): void
    {
        $definition = new ScheduleDefinition('UTC', [['day' => 1, 'from' => '09:00', 'to' => '17:00']], 480, 2400, 30);

        self::assertEquals($definition, ScheduleDefinition::fromArray($definition->toArray()));
        self::assertSame(['timezone', 'slots', 'daily_target_minutes', 'weekly_target_minutes', 'flex_minutes'], array_keys($definition->toArray()));
    }

    public function testFromArrayIgnoresMalformedSlotsAndTakesTheDefaultZone(): void
    {
        $definition = ScheduleDefinition::fromArray(['slots' => ['x', ['day' => '1', 'from' => '09:00', 'to' => '10:00'], ['day' => 3, 'from' => '09:00', 'to' => '10:00']]], 'Europe/Madrid');

        self::assertSame('Europe/Madrid', $definition->timezone);
        self::assertCount(1, $definition->slots);
        self::assertSame(0, $definition->flexMinutes);
        self::assertNull($definition->dailyTargetMinutes);
    }

    public function testFromArrayReadsTheRangesForm(): void
    {
        $definition = ScheduleDefinition::fromArray(['timezone' => 'UTC', 'ranges' => ['mon' => ['09:00-13:00', '14:00-17:00'], 'sun' => ['10:00-11:00']], 'daily_target_minutes' => 420]);

        self::assertCount(3, $definition->slots);
        self::assertSame(7, $definition->slots[2]['day']);
        self::assertSame(420, $definition->dailyTargetMinutes);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badArrays(): iterable
    {
        yield 'unknown day key' => [['ranges' => ['xyz' => ['09:00-10:00']]]];
        yield 'ranges not a list' => [['ranges' => ['mon' => '09:00-10:00']]];
        yield 'range not a string' => [['ranges' => ['mon' => [900]]]];
        yield 'range not HH:MM-HH:MM' => [['ranges' => ['mon' => ['9-10']]]];
        yield 'bad zone' => [['timezone' => 'Mars/Base']];
        yield 'reversed range' => [['ranges' => ['mon' => ['10:00-09:00']]]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('badArrays')]
    public function testFromArrayRefusals(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScheduleDefinition::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badConstructions(): iterable
    {
        yield 'day 0' => [['slots' => [['day' => 0, 'from' => '09:00', 'to' => '10:00']]]];
        yield 'day 8' => [['slots' => [['day' => 8, 'from' => '09:00', 'to' => '10:00']]]];
        yield 'overlap' => [['slots' => [['day' => 1, 'from' => '09:00', 'to' => '11:00'], ['day' => 1, 'from' => '10:00', 'to' => '12:00']]]];
        yield 'equal bounds' => [['slots' => [['day' => 1, 'from' => '09:00', 'to' => '09:00']]]];
        yield 'bad time' => [['slots' => [['day' => 1, 'from' => '9am', 'to' => '10:00']]]];
        yield 'negative daily' => [['daily' => -1]];
        yield 'daily too long' => [['daily' => 721]];
        yield 'negative weekly' => [['weekly' => -1]];
        yield 'weekly too long' => [['weekly' => 5041]];
        yield 'negative flex' => [['flex' => -1]];
        yield 'flex too long' => [['flex' => 241]];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('badConstructions')]
    public function testConstructorRefusals(array $args): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @var list<array{day: int, from: string, to: string}> $slots */
        $slots = $args['slots'] ?? [];
        /** @var ?int $daily */
        $daily = $args['daily'] ?? null;
        /** @var ?int $weekly */
        $weekly = $args['weekly'] ?? null;
        /** @var int $flex */
        $flex = $args['flex'] ?? 0;

        new ScheduleDefinition('UTC', $slots, $daily, $weekly, $flex);
    }

    public function testMinutesAcceptsTwentyFourHundred(): void
    {
        self::assertSame(1440, ScheduleDefinition::minutes('24:00'));
        self::assertSame(75, ScheduleDefinition::minutes('01:15'));
    }

    public function testDayScheduleRoundTripAndDefaults(): void
    {
        $schedule = new DaySchedule('Europe/Madrid', [['from' => '09:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '18:00']], 28_800, 30_000, 144_000, 15, true, 30);

        self::assertEquals($schedule, DaySchedule::fromArray($schedule->toArray()));
        self::assertSame(480, $schedule->expectedMinutes());
        self::assertTrue($schedule->isWorkingDay());
        self::assertSame(30_000, $schedule->dailyThreshold());
        self::assertSame('Europe/Madrid', $schedule->zone()->getName());
    }

    public function testDayScheduleFromArrayIsDefensive(): void
    {
        $schedule = DaySchedule::fromArray(['timezone' => 'Nope/Zone', 'ranges' => ['x', ['from' => '09:00'], ['from' => '09:00', 'to' => '10:00']], 'expectedSeconds' => 'x']);

        self::assertSame('UTC', $schedule->timezone);
        self::assertCount(1, $schedule->ranges);
        self::assertSame(0, $schedule->expectedSeconds);
        self::assertFalse($schedule->isWorkingDay());
        self::assertNull($schedule->dailyThreshold());
        self::assertFalse($schedule->breaksCounted);

        $noRanges = DaySchedule::fromArray(['timezone' => 123]);
        self::assertSame('UTC', $noRanges->timezone);
        self::assertSame([], $noRanges->ranges);
    }

    public function testDayScheduleStartAndEnd(): void
    {
        $date     = new LocalDate('2026-11-10');
        $schedule = new DaySchedule('Europe/Madrid', [['from' => '09:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '18:30']]);

        self::assertSame('2026-11-10 09:00', $schedule->start($date)?->format('Y-m-d H:i'));
        self::assertSame('2026-11-10 18:30', $schedule->end($date)?->format('Y-m-d H:i'));
        self::assertNull((new DaySchedule('UTC'))->start($date));
        self::assertNull((new DaySchedule('UTC'))->end($date));
    }

    public function testDailyThresholdFallsBackToExpected(): void
    {
        self::assertSame(3600, (new DaySchedule('UTC', [], 3600))->dailyThreshold());
    }
}
