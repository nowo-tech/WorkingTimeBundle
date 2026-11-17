<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use PHPUnit\Framework\TestCase;

use function in_array;

/**
 * Behaviour of the register enums (state machine of the clock, revision kinds).
 */
final class EnumTest extends TestCase
{
    public function testClockActionTransitionTable(): void
    {
        $expected = [
            'clock-in'    => [ClockStatus::Idle, ClockStatus::Closed],
            'break-start' => [ClockStatus::Working],
            'break-end'   => [ClockStatus::OnBreak],
            'clock-out'   => [ClockStatus::Working, ClockStatus::OnBreak],
        ];

        foreach (ClockAction::cases() as $action) {
            foreach (ClockStatus::cases() as $status) {
                self::assertSame(in_array($status, $expected[$action->value], true), $action->allowedIn($status), $action->value . ' in ' . $status->value);
            }
        }
    }

    public function testClockActionMappings(): void
    {
        self::assertSame(RevisionKind::ClockIn, ClockAction::ClockIn->revisionKind());
        self::assertSame(RevisionKind::BreakStart, ClockAction::BreakStart->revisionKind());
        self::assertSame(RevisionKind::BreakEnd, ClockAction::BreakEnd->revisionKind());
        self::assertSame(RevisionKind::ClockOut, ClockAction::ClockOut->revisionKind());
        self::assertSame(SegmentType::Work, ClockAction::ClockIn->opens());
        self::assertSame(SegmentType::Work, ClockAction::BreakEnd->opens());
        self::assertSame(SegmentType::Break, ClockAction::BreakStart->opens());
        self::assertNull(ClockAction::ClockOut->opens());
        self::assertSame('break_start', ClockAction::BreakStart->key());
    }

    public function testRevisionKinds(): void
    {
        self::assertTrue(RevisionKind::Correction->isCorrection());
        self::assertTrue(RevisionKind::RecordedCorrection->isCorrection());
        self::assertFalse(RevisionKind::ClockIn->isCorrection());
        self::assertTrue(RevisionKind::BreakEnd->isClock());
        self::assertFalse(RevisionKind::Acknowledged->isClock());
    }

    public function testBeaconStoresAmendAsChange(): void
    {
        self::assertSame(CorrectionOperation::Change, CorrectionOperation::from('amend'));
        self::assertSame(1, ChainFormat::Beacon->value);
        self::assertSame(2, ChainFormat::Bundle->value);
    }
}
