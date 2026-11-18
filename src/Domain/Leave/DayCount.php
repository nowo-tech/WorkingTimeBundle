<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;

use function count;

/**
 * What a date range costs a worker for one leave type (contract section 5; spec 204 FR-009): the total and each counted
 * day (stored units), plus the holidays met on the way (shown in the request dialog).
 */
final readonly class DayCount
{
    /**
     * @param array<string, int> $days `Y-m-d` => amount, only the days that count
     * @param array<string, string> $holidays `Y-m-d` => holiday name, inside the range
     */
    public function __construct(
        public LeaveUnit $unit,
        public int $amount,
        public array $days,
        public array $holidays,
    ) {
    }

    /** The total as an amount. */
    public function total(): LeaveAmount
    {
        return new LeaveAmount($this->unit, $this->amount);
    }

    /**
     * The count as the UI's JSON shows it.
     *
     * @return array{amount: int, formatted: string, unit: string, days: int, holidays: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'amount'    => $this->amount,
            'formatted' => $this->unit->format($this->amount),
            'unit'      => $this->unit->value,
            'days'      => count($this->days),
            'holidays'  => $this->holidays,
        ];
    }
}
