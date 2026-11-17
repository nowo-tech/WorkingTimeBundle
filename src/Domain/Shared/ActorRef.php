<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

/**
 * The person acting (a manager deciding, an admin exporting), as the host identifies them. Opaque, compared byte for byte.
 */
final readonly class ActorRef
{
    use OpaqueRefTrait;

    /**
     * The worker with the same id: an actor acting on their own register.
     */
    public function asWorker(): WorkerRef
    {
        return new WorkerRef($this->value);
    }
}
