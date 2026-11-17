<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Exception;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\WorkingTimeExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A correction decision on a version that is no longer current: another manager decided (or the request changed) at the same moment (contract section 15.2, Q-O3a). Answers 409 + `Retry-After` through transaction-doctrine's responder; nothing stored, no notice. JSON `{"error": "correction_decision_stale"}`.
 */
final class StaleCorrectionDecisionException extends RuntimeException implements LockConflictExceptionInterface, WorkingTimeExceptionInterface
{
    /** JSON error code. */
    public const ERROR_CODE = 'correction_decision_stale';

    /**
     * Client-safe constant message.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Stale version.', 0, $previous);
    }
}
