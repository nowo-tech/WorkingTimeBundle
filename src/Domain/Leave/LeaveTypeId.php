<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave;

use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * Id of a leave type (lowercase UUID).
 */
final readonly class LeaveTypeId
{
    /**
     * @param string $value lowercase UUID
     */
    public function __construct(public string $value)
    {
        Uuid::assert($value, 'leave type id');
    }

    /** Same id. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
