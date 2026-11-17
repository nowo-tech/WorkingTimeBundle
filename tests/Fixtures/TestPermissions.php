<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;

/**
 * Permission checker of the test host (grants in the context).
 */
final class TestPermissions implements WorkingTimePermissionCheckerInterface
{
    public function __construct(public readonly TestContext $context)
    {
    }

    public function isGranted(WorkingTimePermission $permission, TenantRef $tenant, ?WorkerRef $subject = null): bool
    {
        return $this->context->actor !== null && $this->context->holds($tenant->value, $this->context->actor, $permission, $subject);
    }

    public function currentActor(): ?ActorRef
    {
        return $this->context->actorRef();
    }
}
