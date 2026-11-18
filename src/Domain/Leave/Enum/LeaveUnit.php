<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Enum;

use function abs;
use function intdiv;
use function number_format;
use function round;
use function sprintf;

/**
 * How a leave type counts (spec 204 FR-009). Amounts are integers: **half-days** for the two day units (2 = one day,
 * so half-days and the half-day pro-rata rounding stay exact) and **minutes** for `hours` (Beacon's stored units).
 */
enum LeaveUnit: string
{
    case NaturalDays = 'natural_days';
    case WorkingDays = 'working_days';
    case Hours       = 'hours';

    /** Whether the unit counts days. */
    public function isDays(): bool
    {
        return $this !== self::Hours;
    }

    /** The stored amount of one whole unit (a day = 2 half-days, an hour = 60 minutes). */
    public function perUnit(): int
    {
        return $this->isDays() ? 2 : 60;
    }

    /** A form value (days or hours, decimals allowed) as a stored amount, rounded to the nearest half-day / minute. */
    public function fromInput(float $value): int
    {
        return (int) round($value * $this->perUnit());
    }

    /** A stored amount as the number a form shows. */
    public function toInput(int $amount): float
    {
        return $amount / $this->perUnit();
    }

    /** `4.5` (days) or `7:30` (hours). */
    public function format(int $amount): string
    {
        if ($this->isDays()) {
            return $amount % 2 === 0 ? (string) intdiv($amount, 2) : number_format($amount / 2, 1, '.', '');
        }

        return sprintf('%s%d:%02d', $amount < 0 ? '-' : '', intdiv(abs($amount), 60), abs($amount) % 60);
    }

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'leave.unit.' . $this->value;
    }
}
