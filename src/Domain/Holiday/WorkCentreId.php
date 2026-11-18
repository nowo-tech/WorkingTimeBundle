<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * Id of a work centre (lowercase UUID).
 */
final readonly class WorkCentreId
{
    /**
     * @param string $value lowercase UUID
     */
    public function __construct(public string $value)
    {
        Uuid::assert($value, 'work centre id');
    }

    /** Same id. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
