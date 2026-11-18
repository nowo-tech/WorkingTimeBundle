<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Shared;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\WorkingTimeException;
use RuntimeException;
use Throwable;

/**
 * Another writer changed the same rows at the same moment and a second attempt did not settle it, or the version the
 * form showed is no longer current (contract section 15; REQ-RUNTIME-003): answers 409 + `Retry-After`, nothing of
 * this write is stored. The error code names the write (`leave_seed_conflict`, `holiday_import_conflict`,
 * `leave_type_stale`, `holiday_calendar_stale`).
 */
final class WriteConflictException extends RuntimeException implements LockConflictExceptionInterface, WorkingTimeException
{
    /**
     * @param string $errorCode the JSON error code of the conflict
     */
    public function __construct(public readonly string $errorCode, ?Throwable $previous = null)
    {
        parent::__construct('Concurrent change.', 0, $previous);
    }

    /**
     * Runs a write unit; a unique key refused by a racing writer runs it once more against the fresh rows (an
     * idempotent write then finds the rows the other writer stored); a second refusal is a 409.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws self
     *
     * @return T
     */
    public static function writeOnceMore(TransactionRunnerInterface $runner, callable $work, string $errorCode): mixed
    {
        try {
            return $runner->write($work);
        } catch (DuplicateRowException) {
            try {
                return $runner->write($work);
            } catch (DuplicateRowException $e) {
                throw new self($errorCode, $e);
            }
        }
    }
}
