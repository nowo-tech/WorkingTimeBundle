<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Application;

use Nowo\WorkingTimeBundle\Application\Register\Notice\HoursWindow;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;

/**
 * The window around scheduled hours in which notices may be delivered.
 */
final class HoursWindowTest extends TestCase
{
    private function schedule(): DaySchedule
    {
        return new DaySchedule('UTC', [['from' => '09:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '18:00']], 28_800);
    }

    public function testInside(): void
    {
        $date = new LocalDate('2026-11-10');

        self::assertTrue(HoursWindow::inside($date, $this->schedule(), Fx::at('2026-11-10 08:30:00'), 30));
        self::assertTrue(HoursWindow::inside($date, $this->schedule(), Fx::at('2026-11-10 18:30:00'), 30));
        self::assertFalse(HoursWindow::inside($date, $this->schedule(), Fx::at('2026-11-10 08:29:59'), 30));
        self::assertFalse(HoursWindow::inside($date, $this->schedule(), Fx::at('2026-11-10 19:00:00'), 30));
        self::assertFalse(HoursWindow::inside($date, new DaySchedule('UTC'), Fx::at('2026-11-10 10:00:00'), 30));
    }

    public function testReminder(): void
    {
        $date = new LocalDate('2026-11-10');

        self::assertSame('in', HoursWindow::reminder($date, $this->schedule(), Fx::at('2026-11-10 08:45:00'), 30));
        self::assertSame('in', HoursWindow::reminder($date, $this->schedule(), Fx::at('2026-11-10 09:30:00'), 30));
        self::assertSame('out', HoursWindow::reminder($date, $this->schedule(), Fx::at('2026-11-10 17:30:00'), 30));
        self::assertNull(HoursWindow::reminder($date, $this->schedule(), Fx::at('2026-11-10 12:00:00'), 30));
        self::assertNull(HoursWindow::reminder($date, new DaySchedule('UTC'), Fx::at('2026-11-10 09:00:00'), 30));
    }

    public function testNextDeliverable(): void
    {
        $days = ['2026-11-10' => $this->schedule(), '2026-11-11' => $this->schedule()];

        self::assertSame('2026-11-10 08:30:00', HoursWindow::nextDeliverable($days, Fx::at('2026-11-10 03:00:00'), 30)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-10 10:00:00', HoursWindow::nextDeliverable($days, Fx::at('2026-11-10 10:00:00'), 30)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-11 08:30:00', HoursWindow::nextDeliverable($days, Fx::at('2026-11-10 20:00:00'), 30)->format('Y-m-d H:i:s'));
    }

    public function testNextDeliverableWithoutAnyRangeOrAfterTheLastOne(): void
    {
        $now = Fx::at('2026-11-10 22:00:00');

        self::assertSame($now, HoursWindow::nextDeliverable(['2026-11-10' => new DaySchedule('UTC')], $now, 30));
        self::assertSame($now, HoursWindow::nextDeliverable([], $now, 30));
        self::assertSame('2026-11-11 22:00:00', HoursWindow::nextDeliverable(['2026-11-10' => $this->schedule()], $now, 30)->format('Y-m-d H:i:s'));
    }
}
