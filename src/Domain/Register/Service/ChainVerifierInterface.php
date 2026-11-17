<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use Nowo\WorkingTimeBundle\Domain\Register\Read\VerificationReport;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Checks the register chains (contract section 6.3). Maintenance service: no permission check (console, exports).
 */
interface ChainVerifierInterface
{
    /**
     * Verifies every chain, or one tenant's, or one worker's.
     */
    public function verify(?TenantRef $tenant = null, ?WorkerRef $worker = null): VerificationReport;

    /**
     * The hash of a chain's last link (null: empty chain).
     */
    public function head(TenantRef $tenant, WorkerRef $worker): ?string;
}
