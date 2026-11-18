<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;

/**
 * The editable attributes of a leave type (spec 204 clarifications *Type attributes*). Amounts in stored units.
 * There is no field for a diagnosis, a cause or any health detail (owner fixed point, contract section 14.3).
 */
final readonly class LeaveTypeInput
{
    /**
     * Wires the values (validated by the type).
     */
    public function __construct(
        public string $name,
        public bool $paid = true,
        public AllowanceSource $source = AllowanceSource::None,
        public LeaveUnit $unit = LeaveUnit::WorkingDays,
        public bool $needsApproval = true,
        public bool $needsAttachment = false,
        public bool $medical = false,
        public string $colour = 'blue',
        public ?int $maxPerRequest = null,
        public bool $carryOver = false,
        public ?int $carryOverMax = null,
        public ?string $carryOverExpiry = null,
    ) {
    }
}
