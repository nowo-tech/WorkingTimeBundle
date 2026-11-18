<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\WorkingTimeException;
use RuntimeException;
use Throwable;

/**
 * The leave settings changed since the form was read (contract section 15.2): 409 + Retry-After, nothing stored, the
 * form shown again.
 */
final class StaleLeaveSettingsException extends RuntimeException implements WorkingTimeException, LockConflictExceptionInterface
{
    /** JSON error code. */
    public const ERROR_CODE = 'leave_settings_stale';

    /**
     * Client-safe constant message.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Stale version.', 0, $previous);
    }
}
