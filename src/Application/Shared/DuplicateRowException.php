<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Shared;

use RuntimeException;
use Throwable;

/**
 * A unique key refused a row: another writer stored the same chain seq, request key or dedupe key first. Nothing of
 * the unit was committed.
 */
final class DuplicateRowException extends RuntimeException
{
    /**
     * Wraps the storage failure (its message is not kept: it may hold data).
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('A unique key refused the row.', 0, $previous);
    }
}
