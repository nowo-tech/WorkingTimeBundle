<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Enum;

/**
 * A line on top of a worker's yearly allowance (spec 204 FR-007): a signed manual adjustment, a carry-over from last
 * year (expires on its date) or a protected carry-over (days not taken because of a medical absence or a birth /
 * maternity / paternity leave; 18 months by default). Always with a reason.
 */
enum AllowanceLineKind: string
{
    case Adjustment         = 'adjustment';
    case CarryOver          = 'carry_over';
    case ProtectedCarryOver = 'protected_carry_over';

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'leave.line_kind.' . $this->value;
    }
}
