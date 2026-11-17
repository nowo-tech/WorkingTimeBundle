<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Exception;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\WorkingTimeExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A settings update on a version that is no longer current: another admin saved first (contract section 15.2). Answers 409 + `Retry-After`; nothing stored; the form is shown again.
 */
final class StaleSettingsException extends RuntimeException implements LockConflictExceptionInterface, WorkingTimeExceptionInterface
{
    /** JSON error code. */
    public const ERROR_CODE = 'settings_stale';

    /**
     * Client-safe constant message.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Stale version.', 0, $previous);
    }
}
