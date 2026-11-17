<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Worker directory of the test host.
 */
final class TestDirectory implements WorkerDirectoryInterface
{
    public function __construct(public readonly TestContext $context)
    {
    }

    public function current(): ?WorkerRef
    {
        return $this->context->actor === null ? null : new WorkerRef($this->context->actor);
    }

    public function worker(TenantRef $tenant, WorkerRef $worker): ?WorkerProfile
    {
        return $this->context->members[$tenant->value][$worker->value] ?? null;
    }

    public function workers(TenantRef $tenant, ?GroupRef $group = null): iterable
    {
        foreach ($this->context->members[$tenant->value] ?? [] as $profile) {
            if (!$group instanceof GroupRef || $profile->inGroup($group)) {
                yield $profile;
            }
        }
    }

    public function groups(TenantRef $tenant): array
    {
        return $this->context->groups[$tenant->value] ?? [];
    }

    public function managedBy(TenantRef $tenant, ActorRef $actor): array
    {
        $refs = [];
        foreach ($this->context->manages[$tenant->value][$actor->value] ?? [] as $worker) {
            $refs[] = new WorkerRef($worker);
        }

        return $refs;
    }
}
