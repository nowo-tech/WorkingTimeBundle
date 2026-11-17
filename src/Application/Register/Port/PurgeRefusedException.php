<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

use RuntimeException;
use Throwable;

/**
 * The database triggers refused the purge's deletes: the purge guard is misconfigured (exit code 2).
 */
final class PurgeRefusedException extends RuntimeException
{
    /**
     * Wraps the database failure.
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('The register triggers refused the purge.', 0, $previous);
    }
}
