<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * Where a worker's clock stands (spec 198 FR-008); the values are those of TimeTrack's WorkdayState.
 */
enum ClockStatus: string
{
    case Idle    = 'idle';
    case Working = 'working';
    case OnBreak = 'on_break';
    case Closed  = 'closed';
}
