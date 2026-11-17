<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Stops the worker's running task timer when they clock out or start a break (spec 198 FR-012). Called after the
 * register committed; a failure is logged and never undoes the register. Default: NullTaskTimerLink; the TimeTrack
 * bridge stops TimeTrackBundle's timer.
 */
interface TaskTimerLinkInterface
{
    /**
     * Stops the worker's running timer at the instant, if any.
     */
    public function stopRunning(TenantRef $tenant, WorkerRef $worker, DateTimeImmutable $at): void;
}
