<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Holiday;

use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;

/**
 * The holiday component's gate (contract section 13), independent of the register and leave: deny by default.
 *
 * 1. No tenant, nobody signed in, or the actor not an active member: {@see NotAvailableException} (404).
 * 2. `holidays_manage` not granted for the tenant: {@see AccessDeniedException} (403).
 *
 * Every answer comes from the ports in the call (worker mode: nothing is cached).
 */
final readonly class HolidayAccess
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WorkingTimeTenantResolverInterface $tenants,
        private WorkingTimePermissionCheckerInterface $permissions,
        private WorkerDirectoryInterface $directory,
    ) {
    }

    /**
     * The context of a holiday administration call.
     *
     * @throws NotAvailableException
     * @throws AccessDeniedException
     */
    public function context(): HolidayContext
    {
        $tenant  = $this->tenants->current() ?? throw new NotAvailableException();
        $actor   = $this->permissions->currentActor() ?? throw new NotAvailableException();
        $profile = $this->directory->worker($tenant, $actor->asWorker());
        if (!$profile instanceof WorkerProfile || !$profile->worker->equals($actor->asWorker()) || !$profile->active) {
            throw new NotAvailableException();
        }
        if (!$this->permissions->isGranted(WorkingTimePermission::HolidaysManage, $tenant)) {
            throw new AccessDeniedException();
        }

        return new HolidayContext($tenant, $actor, $profile->locale ?? ($this->tenants->profile($tenant)->locale ?? 'en'));
    }

    /**
     * Whether the actor may administer holidays (menus); never throws.
     */
    public function can(): bool
    {
        try {
            $this->context();

            return true;
        } catch (NotAvailableException|AccessDeniedException) {
            return false;
        }
    }
}
