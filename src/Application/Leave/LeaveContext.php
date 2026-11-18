<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

/**
 * Who acts where in one leave call, after the gate passed: the tenant, its leave and register settings (detached
 * copies read in the call), the actor and their member profile, and the locale. Built per call; never kept.
 */
final readonly class LeaveContext
{
    /**
     * Wires the values.
     */
    public function __construct(
        public TenantRef $tenant,
        public LeaveSettings $settings,
        public RegisterSettings $register,
        public ActorRef $actor,
        public WorkerProfile $actorProfile,
        public string $locale = 'en',
    ) {
    }
}
