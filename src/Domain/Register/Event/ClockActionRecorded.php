<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * A clock action was stored (spec 198 FR-012: the task-timer link reacts). Dispatched after the commit; ids and value objects only.
 */
final readonly class ClockActionRecorded
{
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $worker,
        public ClockAction $action,
        public DateTimeImmutable $at,
    ) {
    }
}
