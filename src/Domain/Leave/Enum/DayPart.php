<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Enum;

/**
 * A whole day, the morning or the afternoon of a day-unit type (one date only), or a time range on each day of an
 * hour-unit type (spec 204 FR-009).
 */
enum DayPart: string
{
    case Full      = 'full';
    case Morning   = 'morning';
    case Afternoon = 'afternoon';
    case Hours     = 'hours';

    /** Whether it is half a day. */
    public function isHalf(): bool
    {
        return $this === self::Morning || $this === self::Afternoon;
    }

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'leave.part.' . $this->value;
    }
}
