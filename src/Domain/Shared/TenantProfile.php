<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

/**
 * A tenant as the host describes it: name (PDF header, notices), default locale and time zone (new schedules).
 */
final readonly class TenantProfile
{
    public function __construct(
        public TenantRef $tenant,
        public string $name,
        public string $locale = 'en',
        public string $timezone = 'UTC',
    ) {
    }
}
