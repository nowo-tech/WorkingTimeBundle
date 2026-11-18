<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Service;

use Nowo\WorkingTimeBundle\Domain\Leave\Balance;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * A worker's leave balances (contract section 7): one balance per allowance type of the tenant (types that draw from
 * nothing have none; archived types only while they still hold something). Reads the tenant's rows in the call; the
 * worker must be an active member of the tenant (else none). No permission check: the host calls it for a worker it
 * may show; the bundle's pages check theirs.
 */
interface LeaveBalanceReaderInterface
{
    /**
     * @return array<string, Balance> leave type id => balance, in the types' order
     */
    public function balances(TenantRef $tenant, WorkerRef $worker, int $year): array;
}
