<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use RuntimeException;

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
