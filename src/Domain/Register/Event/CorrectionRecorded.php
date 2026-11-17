<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * A manager recorded a correction on a worker's day (waits for the worker's answer). Dispatched after the commit; ids and value objects only.
 */
final readonly class CorrectionRecorded
{
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $worker,
        public string $revisionId,
    ) {
    }
}
