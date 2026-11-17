<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;

/**
 * The register's gate (contract section 13), asked by every service and page: deny by default.
 *
 * 1. No tenant, the register off for the tenant (unless the page is the settings page), nobody signed in, or the actor
 *    not a member: {@see RegisterUnavailableException} (404).
 * 2. A subject worker who is not a member of the tenant: 404 (never 403, which would reveal the person).
 * 3. The permission not granted (for the subject when there is one): {@see PermissionDeniedException} (403).
 *
 * Every answer comes from the ports in the call (worker mode: nothing is cached).
 */
final readonly class RegisterAccess
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WorkingTimeTenantResolverInterface $tenants,
        private WorkingTimePermissionCheckerInterface $permissions,
        private WorkerDirectoryInterface $directory,
        private RegisterStoreInterface $store,
        private ClockInterface $clock,
        private int $defaultRetentionYears = 4,
        private int $defaultCorrectionWindowDays = 31,
    ) {
    }

    /**
     * The context of an action of the actor on `$subject` (null: on their own register).
     *
     * @param WorkingTimePermission|null $permission null: membership only (the caller checks a finer rule)
     * @param bool $requireEnabled false only for the settings page (it turns the register on)
     *
     * @throws RegisterUnavailableException
     * @throws PermissionDeniedException
     */
    public function context(?WorkingTimePermission $permission, ?WorkerRef $subject = null, bool $requireEnabled = true): RegisterContext
    {
        $tenant   = $this->tenants->current() ?? throw new RegisterUnavailableException();
        $settings = $this->settings($tenant);
        if ($requireEnabled && !$settings->isEnabled()) {
            throw new RegisterUnavailableException();
        }
        $actor        = $this->permissions->currentActor() ?? throw new RegisterUnavailableException();
        $actorProfile = $this->member($tenant, $actor->asWorker());
        $profile      = !$subject instanceof WorkerRef || $subject->isActor($actor) ? $actorProfile : $this->member($tenant, $subject);
        if ($permission instanceof WorkingTimePermission && !$this->permissions->isGranted($permission, $tenant, $subject)) {
            throw new PermissionDeniedException();
        }

        return new RegisterContext($tenant, $settings, $actor, $actorProfile, $profile, $actorProfile->locale ?? ($this->tenants->profile($tenant)->locale ?? 'en'));
    }

    /**
     * Whether the actor holds the permission (menus, buttons); never throws.
     */
    public function can(WorkingTimePermission $permission, ?WorkerRef $subject = null): bool
    {
        try {
            $this->context($permission, $subject);

            return true;
        } catch (RegisterUnavailableException|PermissionDeniedException) {
            return false;
        }
    }

    /**
     * The tenant's settings, or its defaults (register off) when never saved: not stored by a read.
     */
    public function settings(TenantRef $tenant): RegisterSettings
    {
        return $this->store->settings($tenant)
            ?? RegisterSettings::defaults($tenant->value, $this->clock->now(), $this->defaultRetentionYears, $this->defaultCorrectionWindowDays);
    }

    /**
     * A member of the tenant (exact reference), else 404.
     *
     * @throws RegisterUnavailableException
     */
    public function member(TenantRef $tenant, WorkerRef $worker): WorkerProfile
    {
        $profile = $this->directory->worker($tenant, $worker);
        if (!$profile instanceof WorkerProfile || !$profile->worker->equals($worker) || !$profile->active) {
            throw new RegisterUnavailableException();
        }

        return $profile;
    }
}
