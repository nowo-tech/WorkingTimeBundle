<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

use function str_replace;

/**
 * The four clock actions (spec 198 FR-008); the value is the route segment shared with the TimeTrack client API.
 */
enum ClockAction: string
{
    case ClockIn    = 'clock-in';
    case BreakStart = 'break-start';
    case BreakEnd   = 'break-end';
    case ClockOut   = 'clock-out';

    /** Route requirement. */
    public const REQUIREMENT = 'clock-in|break-start|break-end|clock-out';

    /**
     * Whether the action is allowed in the state (a closed day may be opened again: split shifts).
     */
    public function allowedIn(ClockStatus $status): bool
    {
        return match ($this) {
            self::ClockIn    => $status === ClockStatus::Idle || $status === ClockStatus::Closed,
            self::BreakStart => $status === ClockStatus::Working,
            self::BreakEnd   => $status === ClockStatus::OnBreak,
            self::ClockOut   => $status === ClockStatus::Working || $status === ClockStatus::OnBreak,
        };
    }

    /**
     * The chain link kind the action appends.
     */
    public function revisionKind(): RevisionKind
    {
        return match ($this) {
            self::ClockIn    => RevisionKind::ClockIn,
            self::BreakStart => RevisionKind::BreakStart,
            self::BreakEnd   => RevisionKind::BreakEnd,
            self::ClockOut   => RevisionKind::ClockOut,
        };
    }

    /**
     * The segment type the action opens (null: clock-out opens none).
     */
    public function opens(): ?SegmentType
    {
        return match ($this) {
            self::ClockIn, self::BreakEnd => SegmentType::Work,
            self::BreakStart => SegmentType::Break,
            self::ClockOut   => null,
        };
    }

    /** Translation suffix (`clock_in`). */
    public function key(): string
    {
        return str_replace('-', '_', $this->value);
    }
}
