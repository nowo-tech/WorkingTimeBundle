<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;

/**
 * An amount of leave in stored units (contract section 5): half-days for the day units, minutes for hours.
 */
final readonly class LeaveAmount
{
    /**
     * Wires the values.
     */
    public function __construct(public LeaveUnit $unit, public int $amount)
    {
    }

    /** `4.5` days or `7:30` hours. */
    public function format(): string
    {
        return $this->unit->format($this->amount);
    }
}
