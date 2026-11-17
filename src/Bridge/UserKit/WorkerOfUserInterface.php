<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\UserKit;

use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * The worker reference of a UserKit user (GDPR export / erasure). Default: the user's identifier, as the default
 * worker directory uses; hosts whose worker refs are user ids or UUIDs implement it.
 */
interface WorkerOfUserInterface
{
    /**
     * The worker reference; null when the user is not a worker.
     */
    public function workerOf(object $user, ?string $identifier = null): ?WorkerRef;
}
