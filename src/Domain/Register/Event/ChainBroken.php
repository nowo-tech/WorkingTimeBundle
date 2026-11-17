<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * The verifier found a broken chain. Dispatched after the commit; ids and value objects only.
 */
final readonly class ChainBroken
{
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $worker,
        public int $seq,
        public string $reason,
    ) {
    }
}
