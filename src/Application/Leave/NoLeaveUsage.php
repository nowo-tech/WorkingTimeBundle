<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveUsageInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * No requests yet (S2): every balance is its entitlement.
 */
final readonly class NoLeaveUsage implements LeaveUsageInterface
{
    /**
     * See the implemented interface.
     */
    public function usage(TenantRef $tenant, WorkerRef $worker, int $year, LocalDate $today): array
    {
        return [];
    }
}
