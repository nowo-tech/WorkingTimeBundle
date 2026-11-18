<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Event;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeId;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * A line was added to a worker's allowance (adjustment or carry-over), dispatched after the commit. Ids and amounts
 * only, never the reason.
 */
final readonly class LeaveAllowanceAdjusted
{
    /**
     * Wires the values.
     */
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $worker,
        public LeaveTypeId $type,
        public int $year,
        public AllowanceLineKind $kind,
        public int $amount,
    ) {
    }
}
