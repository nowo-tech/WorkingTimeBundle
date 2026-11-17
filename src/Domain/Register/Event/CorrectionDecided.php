<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * A manager approved or rejected a correction request. Dispatched after the commit; ids and value objects only.
 */
final readonly class CorrectionDecided
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $worker,
        public string $correctionId,
        public bool $approved,
    ) {
    }
}
