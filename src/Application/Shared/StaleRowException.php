<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Shared;

use RuntimeException;
use Throwable;

/**
 * A versioned row changed since it was read (optimistic lock at flush). Nothing of the unit was committed.
 */
final class StaleRowException extends RuntimeException
{
    /**
     * Wraps the storage failure.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The row changed since it was read.', 0, $previous);
    }
}
