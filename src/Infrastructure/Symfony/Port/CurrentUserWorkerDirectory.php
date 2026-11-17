<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function mb_substr;

/**
 * Default worker directory: only the signed-in security user (worker ref = user identifier) is a member; team views
 * show nothing and nobody manages anyone. Hosts with people and groups implement the port.
 */
final readonly class CurrentUserWorkerDirectory implements WorkerDirectoryInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private ?TokenStorageInterface $tokens = null)
    {
    }

    /**
     * The signed-in user.
     */
    public function current(): ?WorkerRef
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user === null ? null : new WorkerRef($user->getUserIdentifier());
    }

    /**
     * The signed-in user's profile when asked about them.
     */
    public function worker(TenantRef $tenant, WorkerRef $worker): ?WorkerProfile
    {
        $current = $this->current();

        return $current !== null && $current->equals($worker) ? new WorkerProfile($worker, mb_substr($worker->value, 0, WorkerProfile::NAME_MAX)) : null;
    }

    /**
     * The signed-in user only.
     */
    public function workers(TenantRef $tenant, ?GroupRef $group = null): iterable
    {
        $current = $this->current();
        if ($current !== null && $group === null) {
            yield new WorkerProfile($current, mb_substr($current->value, 0, WorkerProfile::NAME_MAX));
        }
    }

    /**
     * No groups.
     */
    public function groups(TenantRef $tenant): array
    {
        return [];
    }

    /**
     * Nobody.
     */
    public function managedBy(TenantRef $tenant, ActorRef $actor): array
    {
        return [];
    }
}
