<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;

/**
 * A worker's yearly entitlement of one type (spec 204 FR-005 – FR-007): the policy that applies (most specific), its
 * amount after part-time and pro-rata, and the adjustment / carry-over lines counting on the day of reading. Stored
 * units.
 */
final readonly class Entitlement
{
    /**
     * @param list<AllowanceLine> $lines every line of the worker, type and year (expired ones included, not counted)
     */
    public function __construct(
        public ?AllowancePolicy $policy,
        public int $base,
        public int $lineTotal,
        public array $lines,
        public float $proRata = 1.0,
        public float $partTime = 1.0,
    ) {
    }

    /** Base plus the counting lines. */
    public function total(): int
    {
        return $this->base + $this->lineTotal;
    }
}
