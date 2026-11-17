<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * No task timer to stop (TimeTrack not installed or `register.task_timer_link: off`).
 */
final readonly class NullTaskTimerLink implements TaskTimerLinkInterface
{
    /**
     * Does nothing.
     */
    public function stopRunning(TenantRef $tenant, WorkerRef $worker, DateTimeImmutable $at): void
    {
    }
}
