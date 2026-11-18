<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;

/**
 * The leave gate (contract section 13): deny by default.
 *
 * 1. No tenant, leave off for the tenant (unless the page turns it on), nobody signed in, or the actor not an active
 *    member: {@see NotAvailableException} (404).
 * 2. A subject worker who is not a member: 404.
 * 3. The permission not granted (for the subject when there is one): {@see AccessDeniedException} (403).
 */
final readonly class LeaveAccess
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WorkingTimeTenantResolverInterface $tenants,
        private WorkingTimePermissionCheckerInterface $permissions,
        private RegisterAccess $register,
        private LeaveStoreInterface $store,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The context of a leave call.
     *
     * @param bool $requireEnabled false only for the settings page (it turns leave on)
     *
     * @throws NotAvailableException
     * @throws AccessDeniedException
     */
    public function context(WorkingTimePermission $permission, ?WorkerRef $subject = null, bool $requireEnabled = true): LeaveContext
    {
        $tenant   = $this->tenants->current() ?? throw new NotAvailableException();
        $settings = $this->settings($tenant);
        if ($requireEnabled && !$settings->isEnabled()) {
            throw new NotAvailableException();
        }
        $actor   = $this->permissions->currentActor() ?? throw new NotAvailableException();
        $profile = $this->register->member($tenant, $actor->asWorker());
        if ($subject instanceof WorkerRef && !$subject->isActor($actor)) {
            $this->register->member($tenant, $subject);
        }
        if (!$this->permissions->isGranted($permission, $tenant, $subject)) {
            throw new AccessDeniedException();
        }

        return new LeaveContext($tenant, $settings, $this->register->settings($tenant), $actor, $profile, $profile->locale ?? ($this->tenants->profile($tenant)->locale ?? 'en'));
    }

    /**
     * Whether the actor holds the permission (menus); never throws.
     *
     * @param bool $requireEnabled false for the settings entry (it turns leave on)
     */
    public function can(WorkingTimePermission $permission, bool $requireEnabled = true): bool
    {
        try {
            $this->context($permission, null, $requireEnabled);

            return true;
        } catch (NotAvailableException|AccessDeniedException) {
            return false;
        }
    }

    /**
     * The tenant's leave settings, or the defaults (leave off) when never saved.
     */
    public function settings(TenantRef $tenant): LeaveSettings
    {
        return $this->store->settings($tenant) ?? LeaveSettings::defaults($tenant->value, $this->clock->now());
    }

    /**
     * An active member of the tenant, else 404.
     *
     * @throws NotAvailableException
     */
    public function member(TenantRef $tenant, WorkerRef $worker): WorkerProfile
    {
        return $this->register->member($tenant, $worker);
    }
}
