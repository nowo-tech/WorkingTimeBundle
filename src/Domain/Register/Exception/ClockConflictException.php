<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use RuntimeException;

/**
 * The clock action does not fit the worker's current state, is earlier than the day's last recorded time, or lost a
 * race (spec 198 FR-008 / FR-011). Answers 409 with the state; never retried blindly (the state changed).
 */
final class ClockConflictException extends RuntimeException implements WorkingTimeExceptionInterface
{
    /** The action is not allowed in the state. */
    public const NOT_ALLOWED = 'not_allowed';

    /** The time is earlier than the day's last recorded time. */
    public const EARLIER_THAN_LAST = 'earlier_than_last';

    /** Another request changed the state first. */
    public const CONCURRENT = 'concurrent';

    /**
     * @param ClockState $state the state now
     * @param string $reason one of the constants
     */
    public function __construct(public readonly ClockState $state, public readonly string $reason = self::NOT_ALLOWED)
    {
        parent::__construct('Clock conflict: ' . $reason);
    }
}
