<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\TenantProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * The tenant of the current call (contract section 8.1). Default: SingleTenantResolver (`TenantRef::default()`).
 * Asked per call, never cached (worker mode).
 */
interface WorkingTimeTenantResolverInterface
{
    /**
     * Tenant of the current call; null = no tenant (personal context): every page and action answers 404.
     */
    public function current(): ?TenantRef;

    /**
     * Name, locale and time zone of a tenant (PDF header, notices); null when the host does not know it.
     */
    public function profile(TenantRef $tenant): ?TenantProfile;
}
