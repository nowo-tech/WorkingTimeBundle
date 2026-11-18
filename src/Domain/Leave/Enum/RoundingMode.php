<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Enum;

use function ceil;
use function floor;
use function round;

/**
 * How a pro-rata allowance is rounded (spec 204 FR-006): to the nearest half-day (or minute), up or down.
 */
enum RoundingMode: string
{
    case Nearest = 'nearest';
    case Up      = 'up';
    case Down    = 'down';

    /** Rounds a fractional stored amount (half-days or minutes) to a whole one. */
    public function apply(float $amount): int
    {
        return (int) match ($this) {
            self::Nearest => round($amount),
            self::Up      => ceil($amount - 1e-9),
            self::Down    => floor($amount + 1e-9),
        };
    }

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'leave.rounding.' . $this->value;
    }
}
