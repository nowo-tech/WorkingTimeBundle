<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\UserKit;

use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default mapping: the identifier captured before the erasure, else the security user's identifier.
 */
final readonly class IdentifierWorkerOfUser implements WorkerOfUserInterface
{
    /**
     * The worker reference.
     */
    public function workerOf(object $user, ?string $identifier = null): ?WorkerRef
    {
        $identifier ??= $user instanceof UserInterface ? $user->getUserIdentifier() : null;

        return $identifier === null || $identifier === '' ? null : new WorkerRef($identifier);
    }
}
