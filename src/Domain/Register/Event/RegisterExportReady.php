<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * An export file is ready to download. Dispatched after the commit; ids and value objects only.
 */
final readonly class RegisterExportReady
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        public TenantRef $tenant,
        public string $exportId,
    ) {
    }
}
