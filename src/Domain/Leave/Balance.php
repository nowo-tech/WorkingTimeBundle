<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;

use function reset;
use function substr;

/**
 * One type's balance of a worker and year (contract section 5; spec 204 *Balance*): entitled (allowance with its
 * lines), taken (approved, past), planned (approved, from today), pending (not decided) and remaining = entitled −
 * taken − planned (pending shown apart). Taken, planned and pending come from the requests (S3; zero until then).
 */
final readonly class Balance
{
    /**
     * @param list<AllowanceLine> $lines the year's lines of the worker and type
     * @param array<string, string> $typeNames locale => the type's name
     */
    public function __construct(
        public LeaveTypeId $type,
        public LeaveUnit $unit,
        public LeaveAmount $entitled,
        public LeaveAmount $taken,
        public LeaveAmount $planned,
        public LeaveAmount $pending,
        public array $lines = [],
        public ?Entitlement $entitlement = null,
        public array $typeNames = [],
    ) {
    }

    /** The type's name in the locale, else the language's, else English, else the first one. */
    public function typeName(string $locale): string
    {
        foreach ([$locale, substr($locale, 0, 2), 'en'] as $key) {
            if (isset($this->typeNames[$key])) {
                return $this->typeNames[$key];
            }
        }
        $names = $this->typeNames;
        $first = reset($names);

        return $first === false ? $this->type->value : $first;
    }

    /** Entitled − taken − planned. */
    public function remaining(): LeaveAmount
    {
        return new LeaveAmount($this->unit, $this->entitled->amount - $this->taken->amount - $this->planned->amount);
    }

    /** What a new request may still use (pending included, FR-012). */
    public function available(): LeaveAmount
    {
        return new LeaveAmount($this->unit, $this->remaining()->amount - $this->pending->amount);
    }
}
