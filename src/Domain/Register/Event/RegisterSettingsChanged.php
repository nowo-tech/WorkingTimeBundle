<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Event;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * A tenant's register settings changed. Dispatched after the commit; ids and value objects only.
 */
final readonly class RegisterSettingsChanged
{
    public function __construct(
        public TenantRef $tenant,
        public int $version,
    ) {
    }
}
