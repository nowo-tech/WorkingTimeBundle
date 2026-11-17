<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\TimeTrack;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * TimeTrack bridge of the task-timer link (spec 198 FR-012): clocking out or starting a break stops the worker's
 * running TimeTrack timer at the same instant. The task time stays TimeTrack's own record (the register never reads
 * it). Only the signed-in worker's timer is stopped (a worker clocks only for themself).
 */
final readonly class TimeTrackTaskTimerLink implements TaskTimerLinkInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private TimerService $timers,
        private TokenStorageInterface $tokens,
        private WorkerDirectoryInterface $directory,
    ) {
    }

    /**
     * Stops the running timer, if any.
     */
    public function stopRunning(TenantRef $tenant, WorkerRef $worker, DateTimeImmutable $at): void
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof UserInterface || $this->directory->current()?->equals($worker) !== true) {
            return;
        }
        if ($this->timers->getActive($user) !== null) {
            $this->timers->stop($user, $at);
        }
    }
}
