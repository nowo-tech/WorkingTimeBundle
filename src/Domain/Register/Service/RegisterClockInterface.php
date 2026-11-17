<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ClockConflictException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * The register's clock (contract section 7): one state machine for the web, the header and the client API. Both
 * methods pass the gate: the tenant of the call, the register on, the worker a member, `clock` granted, and a worker
 * clocks only for themself.
 */
interface RegisterClockInterface
{
    /**
     * Where the worker's clock stands (null worker: the signed-in one).
     *
     * @throws RegisterUnavailableException
     * @throws PermissionDeniedException
     */
    public function state(?WorkerRef $worker = null): ClockState;

    /**
     * Records an action. `$occurredAt` is a client's offline time (null: now); the raw IP is turned into its /24 or /64
     * prefix only when the tenant enabled IP evidence, and never stored itself. A repeated request key answers the
     * current state without acting again.
     *
     * @throws ClockConflictException 409 with the state
     * @throws RegisterUnavailableException
     * @throws PermissionDeniedException
     */
    public function act(?WorkerRef $worker, ClockAction $action, ?DateTimeImmutable $occurredAt, RequestKey $requestKey, SegmentSource $source, ?string $rawIp): ClockState;
}
