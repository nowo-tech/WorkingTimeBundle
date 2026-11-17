<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;

/**
 * Who may do what (contract sections 8.1 and 13). **Deny by default.** Default: RoleMapPermissionChecker
 * (`permissions.roles`, every list empty = denied).
 */
interface WorkingTimePermissionCheckerInterface
{
    /**
     * Whether the current actor holds the permission in the tenant. `$subject` narrows "manage / export" to one worker:
     * the host answers whether the actor manages that worker; null asks for the tenant-wide grant.
     */
    public function isGranted(WorkingTimePermission $permission, TenantRef $tenant, ?WorkerRef $subject = null): bool;

    /**
     * The person acting in the current call; null = nobody signed in.
     */
    public function currentActor(): ?ActorRef;
}
