<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * The people of a tenant (contract section 8.1). Default: CurrentUserWorkerDirectory (only the signed-in user; team
 * views show nothing).
 */
interface WorkerDirectoryInterface
{
    /**
     * The signed-in person as a worker, per call; null = nobody.
     */
    public function current(): ?WorkerRef;

    /**
     * A member of the tenant; null = not a member (the bundle answers 404).
     */
    public function worker(TenantRef $tenant, WorkerRef $worker): ?WorkerProfile;

    /**
     * Members, optionally of one group, streamed in pages of 500.
     *
     * @return iterable<WorkerProfile>
     */
    public function workers(TenantRef $tenant, ?GroupRef $group = null): iterable;

    /**
     * Groups with labels (schedules).
     *
     * @return list<array{GroupRef, string}>
     */
    public function groups(TenantRef $tenant): array;

    /**
     * Workers the actor manages (team views); the host's rule.
     *
     * @return list<WorkerRef>
     */
    public function managedBy(TenantRef $tenant, ActorRef $actor): array;
}
