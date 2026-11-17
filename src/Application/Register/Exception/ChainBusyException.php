<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Exception;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\WorkingTimeExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Another writer appended to the worker's chain at the same moment, twice in a row (audit ST-M2): nothing of this
 * write was stored. Answers 409 + `Retry-After` through transaction-doctrine's responder (a retry sees the new head).
 */
final class ChainBusyException extends RuntimeException implements LockConflictExceptionInterface, WorkingTimeExceptionInterface
{
    /** JSON error code. */
    public const ERROR_CODE = 'register_chain_busy';

    /**
     * Client-safe constant message.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The register changed at the same moment.', 0, $previous);
    }
}
