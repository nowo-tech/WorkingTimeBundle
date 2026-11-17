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
     * Verifies every chain, or one tenant's, or one worker's. `$publishedHeads` ("tenant\x1Fworker" => hash) are heads
     * published earlier that must still be links of their chains.
     *
     * @param array<string, string> $publishedHeads
     */
    public function verify(?TenantRef $tenant = null, ?WorkerRef $worker = null, array $publishedHeads = []): VerificationReport;

    /**
     * Records the report's heads through the audit sink (`working_time.chain_head`) for a later comparison.
     */
    public function publishHeads(VerificationReport $report): int;

    /**
     * The hash of a chain's last link (null: empty chain).
     */
    public function head(TenantRef $tenant, WorkerRef $worker): ?string;
}
