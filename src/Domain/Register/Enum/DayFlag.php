<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * What the register points out on a day. Flags inform; none ever blocks clocking. Values are Beacon's (client API,
 * CSV).
 */
enum DayFlag: string
{
    /** An open segment past the schedule end + grace (or 16 h): the worker files a correction. */
    case MissingClockOut = 'missing_clock_out';
    /** A client time arrived more than the late threshold after it happened (FR-011). */
    case LateEntry = 'late_entry';
    /** Worked time above the daily threshold. */
    case Overtime = 'overtime';
    /** First start to last end above the long-day hours. */
    case LongDay = 'long_day';
    /** Breaks shorter than the tenant's informational minimum on a day over six hours. */
    case ShortBreak = 'short_break';
    /** First clock-in outside the flexible band around the scheduled start. */
    case OutsideBand = 'outside_band';
    /** A correction was applied. */
    case Corrected = 'corrected';
    /** A manager recorded a correction the worker has not answered yet. */
    case PendingAcknowledgement = 'pending_acknowledgement';
    /** The worker contested a manager's correction. */
    case Contested = 'contested';
    /** A correction request waits for a manager. */
    case CorrectionPending = 'correction_pending';
}
