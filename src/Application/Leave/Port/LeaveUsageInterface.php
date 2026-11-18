<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * What a worker's requests use of each type in a year (stored units): taken (approved, before today), planned
 * (approved, from today) and pending. The requests arrive with S3; until then the default answers nothing.
 */
interface LeaveUsageInterface
{
    /**
     * @return array<string, array{taken: int, planned: int, pending: int}> leave type id => usage
     */
    public function usage(TenantRef $tenant, WorkerRef $worker, int $year, LocalDate $today): array;
}
