<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * Tenant resolver of the test host.
 */
final class TestTenants implements WorkingTimeTenantResolverInterface
{
    public function __construct(public readonly TestContext $context)
    {
    }

    public function current(): ?TenantRef
    {
        return $this->context->tenantRef();
    }

    public function profile(TenantRef $tenant): ?TenantProfile
    {
        return $tenant->value === 'missing' ? null : new TenantProfile($tenant, 'Org ' . $tenant->value, 'en', 'Europe/Madrid');
    }
}
