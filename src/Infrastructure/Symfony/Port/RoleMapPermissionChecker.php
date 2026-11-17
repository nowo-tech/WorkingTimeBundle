<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/**
 * Default permission checker (contract sections 8.1 and 10, Q-O15): `permissions.roles` maps each permission to
 * security roles; **every list empty = denied**. A role grants the permission tenant-wide (a role map cannot say
 * "manages this worker": hosts with team managers implement the port). The actor is the security user's identifier.
 * Without SecurityBundle nothing is granted and nobody acts.
 */
final readonly class RoleMapPermissionChecker implements WorkingTimePermissionCheckerInterface
{
    /**
     * @param array<string, list<string>> $roles permission value => roles
     */
    public function __construct(
        private array $roles = [],
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * Whether a mapped role is held.
     */
    public function isGranted(WorkingTimePermission $permission, TenantRef $tenant, ?WorkerRef $subject = null): bool
    {
        if ($this->authorization === null || $this->currentActor() === null) {
            return false;
        }
        foreach ($this->roles[$permission->value] ?? [] as $role) {
            try {
                if ($this->authorization->isGranted($role)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * The security user's identifier.
     */
    public function currentActor(): ?ActorRef
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user === null ? null : new ActorRef($user->getUserIdentifier());
    }
}
