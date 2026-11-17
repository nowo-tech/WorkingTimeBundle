<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * The retention purge deleted a tenant's old register rows (counts only). Dispatched after the commit; ids and value objects only.
 */
final readonly class RegisterPurged
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        public TenantRef $tenant,
        public string $firstKeptDate,
        public int $workdays,
        public int $revisions,
    ) {
    }
}
