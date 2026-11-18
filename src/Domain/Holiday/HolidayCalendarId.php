<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * Id of a holiday calendar (lowercase UUID).
 */
final readonly class HolidayCalendarId
{
    /**
     * @param string $value lowercase UUID
     */
    public function __construct(public string $value)
    {
        Uuid::assert($value, 'holiday calendar id');
    }

    /** Same id. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
