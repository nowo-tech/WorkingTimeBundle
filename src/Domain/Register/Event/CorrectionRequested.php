<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * A worker asked to correct a day. Dispatched after the commit; ids and value objects only.
 */
final readonly class CorrectionRequested
{
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $worker,
        public string $correctionId,
    ) {
    }
}
