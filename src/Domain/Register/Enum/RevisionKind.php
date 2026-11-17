<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

use function in_array;

/**
 * One link of a worker's register chain (spec 198 FR-015 – FR-018): a clock action, an applied correction (approved
 * request, or recorded by a manager and waiting for the worker's answer) and the worker's answer.
 */
enum RevisionKind: string
{
    case ClockIn            = 'clock_in';
    case BreakStart         = 'break_start';
    case BreakEnd           = 'break_end';
    case ClockOut           = 'clock_out';
    case Correction         = 'correction';
    case RecordedCorrection = 'recorded_correction';
    case Acknowledged       = 'acknowledged';
    case Contested          = 'contested';

    /**
     * Whether the link changes the day (approved or recorded correction).
     */
    public function isCorrection(): bool
    {
        return $this === self::Correction || $this === self::RecordedCorrection;
    }

    /**
     * Whether the link is a clock action.
     */
    public function isClock(): bool
    {
        return in_array($this, [self::ClockIn, self::BreakStart, self::BreakEnd, self::ClockOut], true);
    }
}
