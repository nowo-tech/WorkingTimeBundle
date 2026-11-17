<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

/**
 * A worker (the person whose register it is), as the host identifies them. Opaque, compared byte for byte.
 */
final readonly class WorkerRef
{
    use OpaqueRefTrait;

    /**
     * Whether the actor is this worker (a manager never decides on their own day).
     */
    public function isActor(ActorRef $actor): bool
    {
        return $this->value === $actor->value;
    }
}
