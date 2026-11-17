<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * Default tenant resolver of single-tenant hosts: every call belongs to `TenantRef::default()`.
 */
final readonly class SingleTenantResolver implements WorkingTimeTenantResolverInterface
{
    public function __construct(
        private string $name = 'Working time',
        private string $locale = 'en',
        private string $timezone = 'UTC',
    ) {
    }

    /**
     * The default tenant.
     */
    public function current(): TenantRef
    {
        return TenantRef::default();
    }

    /**
     * The default tenant's profile (null for any other reference).
     */
    public function profile(TenantRef $tenant): ?TenantProfile
    {
        return $tenant->value === TenantRef::DEFAULT ? new TenantProfile($tenant, $this->name, $this->locale, $this->timezone) : null;
    }
}
