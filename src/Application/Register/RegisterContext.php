<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Who acts where in one call, after the gate passed: the tenant, its settings (a detached copy read in the call), the
 * actor and their member profile, and the subject worker when the action is about someone (or the actor themself).
 * Built per call; never kept.
 */
final readonly class RegisterContext
{
    public function __construct(
        public TenantRef $tenant,
        public RegisterSettings $settings,
        public ActorRef $actor,
        public WorkerProfile $actorProfile,
        public WorkerProfile $subject,
        public string $locale = 'en',
    ) {
    }

    /** The subject as a worker reference. */
    public function worker(): WorkerRef
    {
        return $this->subject->worker;
    }

    /** Whether the actor acts on their own register. */
    public function isOwn(): bool
    {
        return $this->subject->worker->isActor($this->actor);
    }
}
