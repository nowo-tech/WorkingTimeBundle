<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\DayCounter;
use Nowo\WorkingTimeBundle\Domain\Leave\DayCount;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\DayPart;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_sum;
use function in_array;
use function intdiv;
use function sprintf;

/**
 * Spec 204 SC-001 (contract section 19, S2 "done when"): {@see DayCounter::compute()} on a fixture year — the public
 * holidays of Madrid 2026 as festivos.io lists them (national, regional and local: tests/Fixtures/holidays), a
 * full-time schedule (Mon–Fri 09:00–17:00), a part-time one (Mon, Wed, Fri 09:00–13:00), a flexible daily target
 * (Mon–Thu 6 h, no ranges), a summer schedule (7 h in July and August) and a schedule change in the middle of a
 * request; natural vs working days, half-days and hours. Beacon's August fortnight cases are kept verbatim. The
 * expected values come from an independent day-by-day count; 100 % of the cases must pass.
 */
final class DayCounterTest extends TestCase
{
    /** Madrid 2026 (festivos.io, recorded): national, regional (2 May) and local (15 May, 9 November). */
    public const MADRID_2026 = [
        '2026-01-01' => 'Año Nuevo', '2026-01-06' => 'Epifanía del Señor', '2026-04-02' => 'Jueves Santo', '2026-04-03' => 'Viernes Santo',
        '2026-05-01' => 'Fiesta del Trabajo', '2026-05-02' => 'Fiesta de la Comunidad de Madrid', '2026-05-15' => 'San Isidro Labrador',
        '2026-08-15' => 'Asunción de la Virgen', '2026-10-12' => 'Fiesta Nacional de España', '2026-11-02' => 'Día siguiente a Todos los Santos',
        '2026-11-09' => 'Nuestra Señora de la Almudena', '2026-12-07' => 'Lunes siguiente al Día de la Constitución Española',
        '2026-12-08' => 'Inmaculada Concepción', '2026-12-25' => 'Natividad del Señor',
    ];

    /** Beacon's fortnight (spec 204 T007): a regional Monday, a local Wednesday and a national Saturday. */
    private const BEACON_AUGUST = ['2026-08-10' => 'Regional day', '2026-08-12' => 'Town fair', '2026-08-15' => 'Assumption'];

    /**
     * @return iterable<string, array{LeaveUnit, string, string, string, string, DayPart, ?string, ?string, int, int}>
     */
    public static function fixtureYear(): iterable
    {
        // unit, from, to, schedule, holidays (madrid | beacon | none), part, start, end, amount, counted days
        yield 'working days, full time, the whole year' => [LeaveUnit::WorkingDays, '2026-01-01', '2026-12-31', 'full', 'madrid', DayPart::Full, null, null, 498, 249];
        yield 'working days, full time, a year without holidays' => [LeaveUnit::WorkingDays, '2026-01-01', '2026-12-31', 'full', 'none', DayPart::Full, null, null, 522, 261];
        yield 'working days, part time, the whole year' => [LeaveUnit::WorkingDays, '2026-01-01', '2026-12-31', 'part', 'madrid', DayPart::Full, null, null, 296, 148];
        yield 'natural days, the whole year' => [LeaveUnit::NaturalDays, '2026-01-01', '2026-12-31', 'full', 'madrid', DayPart::Full, null, null, 730, 365];
        yield 'Easter fortnight' => [LeaveUnit::WorkingDays, '2026-03-30', '2026-04-10', 'full', 'madrid', DayPart::Full, null, null, 16, 8];
        yield 'May with national, regional and local holidays' => [LeaveUnit::WorkingDays, '2026-04-27', '2026-05-17', 'full', 'madrid', DayPart::Full, null, null, 26, 13];
        yield 'May, part time' => [LeaveUnit::WorkingDays, '2026-04-27', '2026-05-17', 'part', 'madrid', DayPart::Full, null, null, 14, 7];
        yield 'December, full time' => [LeaveUnit::WorkingDays, '2026-12-01', '2026-12-31', 'full', 'madrid', DayPart::Full, null, null, 40, 20];
        yield 'December, a daily target without ranges' => [LeaveUnit::WorkingDays, '2026-12-01', '2026-12-31', 'target', 'madrid', DayPart::Full, null, null, 34, 17];
        yield 'a schedule change in the middle (August)' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'change', 'madrid', DayPart::Full, null, null, 16, 8];
        yield 'into the summer schedule' => [LeaveUnit::WorkingDays, '2026-06-22', '2026-07-10', 'summer', 'madrid', DayPart::Full, null, null, 30, 15];
        yield 'natural days across the new year' => [LeaveUnit::NaturalDays, '2026-12-24', '2027-01-06', 'full', 'madrid', DayPart::Full, null, null, 28, 14];
        yield 'working days across the new year' => [LeaveUnit::WorkingDays, '2026-12-24', '2027-01-06', 'full', 'madrid', DayPart::Full, null, null, 18, 9];
        yield 'hours on two weeks with two Monday holidays' => [LeaveUnit::Hours, '2026-11-02', '2026-11-13', 'full', 'madrid', DayPart::Hours, '09:00', '11:30', 1200, 8];
        yield 'hours capped at a part-time day' => [LeaveUnit::Hours, '2026-11-02', '2026-11-13', 'part', 'madrid', DayPart::Hours, '08:00', '20:00', 960, 4];
        yield 'hours capped at a daily target' => [LeaveUnit::Hours, '2026-12-01', '2026-12-11', 'target', 'madrid', DayPart::Hours, '09:00', '17:00', 1800, 5];
        yield 'hours capped at the summer day' => [LeaveUnit::Hours, '2026-06-29', '2026-07-03', 'summer', 'madrid', DayPart::Hours, '08:00', '16:00', 2220, 5];
        yield 'morning of a local holiday' => [LeaveUnit::WorkingDays, '2026-05-15', '2026-05-15', 'full', 'madrid', DayPart::Morning, null, null, 0, 0];
        yield 'afternoon of a working day' => [LeaveUnit::WorkingDays, '2026-05-14', '2026-05-14', 'full', 'madrid', DayPart::Afternoon, null, null, 1, 1];
        yield 'natural half-day on a holiday' => [LeaveUnit::NaturalDays, '2026-05-15', '2026-05-15', 'full', 'madrid', DayPart::Morning, null, null, 1, 1];
        yield 'a Saturday is no working day' => [LeaveUnit::WorkingDays, '2026-05-02', '2026-05-02', 'full', 'none', DayPart::Full, null, null, 0, 0];
        // Beacon's DayCounterTest cases (spec 204 T007), verbatim.
        yield 'beacon: working days, full time, no holidays' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'full', 'none', DayPart::Full, null, null, 20, 10];
        yield 'beacon: working days, full time, two weekday holidays' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'full', 'beacon', DayPart::Full, null, null, 16, 8];
        yield 'beacon: natural days count holidays and weekends' => [LeaveUnit::NaturalDays, '2026-08-03', '2026-08-16', 'full', 'beacon', DayPart::Full, null, null, 28, 14];
        yield 'beacon: part time, no holidays' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'part', 'none', DayPart::Full, null, null, 12, 6];
        yield 'beacon: part time, with holidays' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'part', 'beacon', DayPart::Full, null, null, 8, 4];
        yield 'beacon: schedule change in the middle' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'change', 'none', DayPart::Full, null, null, 16, 8];
        yield 'beacon: schedule change with holidays' => [LeaveUnit::WorkingDays, '2026-08-03', '2026-08-14', 'change', 'beacon', DayPart::Full, null, null, 12, 6];
        yield 'beacon: morning of a working day' => [LeaveUnit::WorkingDays, '2026-08-04', '2026-08-04', 'full', 'beacon', DayPart::Morning, null, null, 1, 1];
        yield 'beacon: afternoon of a holiday' => [LeaveUnit::WorkingDays, '2026-08-10', '2026-08-10', 'full', 'beacon', DayPart::Afternoon, null, null, 0, 0];
        yield 'beacon: half of a Saturday' => [LeaveUnit::WorkingDays, '2026-08-08', '2026-08-08', 'full', 'beacon', DayPart::Morning, null, null, 0, 0];
        yield 'beacon: natural half-day on a Sunday' => [LeaveUnit::NaturalDays, '2026-08-09', '2026-08-09', 'full', 'beacon', DayPart::Afternoon, null, null, 1, 1];
        yield 'beacon: two hours on two days' => [LeaveUnit::Hours, '2026-08-03', '2026-08-04', 'full', 'beacon', DayPart::Hours, '10:00', '12:00', 240, 2];
        yield 'beacon: hours capped at the scheduled day' => [LeaveUnit::Hours, '2026-08-03', '2026-08-04', 'full', 'beacon', DayPart::Hours, '07:00', '19:00', 960, 2];
        yield 'beacon: hours capped at a part-time day' => [LeaveUnit::Hours, '2026-08-03', '2026-08-04', 'part', 'beacon', DayPart::Hours, '09:00', '17:00', 240, 1];
        yield 'beacon: hours on a holiday and a weekend' => [LeaveUnit::Hours, '2026-08-08', '2026-08-10', 'full', 'beacon', DayPart::Hours, '09:00', '11:00', 0, 0];
    }

    #[DataProvider('fixtureYear')]
    public function testTheFixtureYear(LeaveUnit $unit, string $from, string $to, string $schedule, string $holidays, DayPart $part, ?string $start, ?string $end, int $amount, int $counted): void
    {
        DayCounter::validate($unit, new LocalDate($from), new LocalDate($to), $part, $start, $end);
        $map = match ($holidays) {
            'madrid' => self::MADRID_2026,
            'beacon' => self::BEACON_AUGUST,
            default  => [],
        };
        $count = DayCounter::compute($unit, self::days($from, $to, $schedule), $map, $part, $start, $end);

        self::assertSame($amount, $count->amount);
        self::assertCount($counted, $count->days);
        self::assertSame($amount, array_sum($count->days));
        foreach ($count->days as $date => $cost) {
            self::assertGreaterThan(0, $cost);
            if ($unit !== LeaveUnit::NaturalDays) {
                self::assertArrayNotHasKey($date, $map, 'a holiday never counts as a working day');
            }
        }
    }

    public function testTheCountListsTheHolidaysInsideTheRange(): void
    {
        $count = DayCounter::compute(LeaveUnit::WorkingDays, self::days('2026-04-27', '2026-05-04', 'full'), self::MADRID_2026);

        self::assertSame(['2026-05-01' => 'Fiesta del Trabajo', '2026-05-02' => 'Fiesta de la Comunidad de Madrid'], $count->holidays);
        self::assertSame(['amount' => 10, 'formatted' => '5', 'unit' => 'working_days', 'days' => 5, 'holidays' => $count->holidays], $count->toArray());
        self::assertSame('5', $count->total()->format());
        self::assertSame('4.5', (new DayCount(LeaveUnit::NaturalDays, 9, [], []))->toArray()['formatted']);
    }

    public function testADailyTargetAloneMakesAWorkingDay(): void
    {
        self::assertTrue(DayCounter::isWorkingDay(new DaySchedule('UTC', [], 3_600)));
        self::assertTrue(DayCounter::isWorkingDay(new DaySchedule('UTC', [['from' => '09:00', 'to' => '10:00']], 0)));
        self::assertFalse(DayCounter::isWorkingDay(new DaySchedule('UTC')));
    }

    /**
     * @return iterable<string, array{LeaveUnit, string, string, DayPart, ?string, ?string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'end before start' => [LeaveUnit::WorkingDays, '2026-08-05', '2026-08-04', DayPart::Full, null, null, 'leave.request.error.end_before_start'];
        yield 'over a year' => [LeaveUnit::NaturalDays, '2026-01-01', '2027-01-02', DayPart::Full, null, null, 'leave.request.error.too_long'];
        yield 'half-day over two dates' => [LeaveUnit::WorkingDays, '2026-08-04', '2026-08-05', DayPart::Morning, null, null, 'leave.request.error.half_day_range'];
        yield 'hours on a day type' => [LeaveUnit::WorkingDays, '2026-08-04', '2026-08-04', DayPart::Hours, '09:00', '10:00', 'leave.request.error.part'];
        yield 'a whole day of an hour type' => [LeaveUnit::Hours, '2026-08-04', '2026-08-04', DayPart::Full, null, null, 'leave.request.error.part'];
        yield 'no times' => [LeaveUnit::Hours, '2026-08-04', '2026-08-04', DayPart::Hours, null, '10:00', 'leave.request.error.times'];
        yield 'end before the start time' => [LeaveUnit::Hours, '2026-08-04', '2026-08-04', DayPart::Hours, '11:00', '10:00', 'leave.request.error.times'];
        yield 'not a time' => [LeaveUnit::Hours, '2026-08-04', '2026-08-04', DayPart::Hours, '25:00', '26:00', 'leave.request.error.times'];
    }

    #[DataProvider('refusals')]
    public function testRefusals(LeaveUnit $unit, string $from, string $to, DayPart $part, ?string $start, ?string $end, string $error): void
    {
        try {
            DayCounter::validate($unit, new LocalDate($from), new LocalDate($to), $part, $start, $end);
            self::fail($error);
        } catch (LeaveRefusedException $e) {
            self::assertSame($error, $e->key);
        }
    }

    /**
     * @return array<string, DaySchedule>
     */
    public static function days(string $from, string $to, string $schedule): array
    {
        $days = [];
        for ($date = new LocalDate($from); $date->compare(new LocalDate($to)) <= 0; $date = $date->plusDays(1)) {
            $weekday = $date->weekday();
            $summer  = $date->value >= '2026-07-01' && $date->value <= '2026-08-31';
            $minutes = match ($schedule) {
                'part'   => in_array($weekday, [1, 3, 5], true) ? 240 : 0,
                'target' => $weekday <= 4 ? 360 : 0,
                'change' => $date->value < '2026-08-10' ? ($weekday <= 5 ? 480 : 0) : (in_array($weekday, [1, 3, 5], true) ? 240 : 0),
                'summer' => $weekday <= 5 ? ($summer ? 420 : 480) : 0,
                default  => $weekday <= 5 ? 480 : 0,
            };
            $ranges             = $minutes === 0 || $schedule === 'target' ? [] : [['from' => '09:00', 'to' => sprintf('%02d:%02d', 9 + intdiv($minutes, 60), $minutes % 60)]];
            $days[$date->value] = new DaySchedule('Europe/Madrid', $ranges, $minutes * 60);
        }

        return $days;
    }
}
