<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveUsageInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Balance;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveAmount;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeId;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveBalanceReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Psr\Clock\ClockInterface;

/**
 * A worker's balances of a year (contract section 7; spec 204 FR-011; Beacon's `LeaveBalances`): one balance per
 * allowance type — the entitlement ({@see AllowanceResolver}) and the usage of the requests ({@see LeaveUsageInterface};
 * none before S3). Four queries whatever the number of types (types, policies, lines, settings) plus the usage. The
 * compensatory time-off credit (overtime types) arrives with the ratification ledger (S3).
 */
final readonly class LeaveBalanceReader implements LeaveBalanceReaderInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private LeaveStoreInterface $store,
        private LeaveUsageInterface $usage,
        private WorkerDirectoryInterface $directory,
        private TransactionRunnerInterface $runner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * See the implemented interface.
     */
    public function balances(TenantRef $tenant, WorkerRef $worker, int $year): array
    {
        $profile = $this->directory->worker($tenant, $worker);
        if (!$profile instanceof WorkerProfile || !$profile->worker->equals($worker) || !$profile->active) {
            return [];
        }

        return $this->runner->read(function () use ($tenant, $profile, $year): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $now      = $this->clock->now();
            $today    = LocalDate::fromDateTime($now);
            $settings = $this->store->settings($tenant) ?? LeaveSettings::defaults($tenant->value, $now);
            $policies = $this->store->policies($tenant, $year);
            $lines    = $this->store->lines($tenant, $profile->worker, $year);
            $usage    = $this->usage->usage($tenant, $profile->worker, $year, $today);
            $balances = [];
            foreach ($this->store->types($tenant) as $type) {
                if ($type->getSource() !== AllowanceSource::Allowance) {
                    continue;
                }
                $entitlement = AllowanceResolver::entitlement($settings->rounding(), $type, $profile, $year, $policies, $lines, $today);
                $used        = $usage[$type->getId()] ?? ['taken' => 0, 'planned' => 0, 'pending' => 0];
                if ($type->isArchived() && $entitlement->total() === 0 && $used['taken'] + $used['planned'] + $used['pending'] === 0) {
                    continue;
                }
                $unit                     = $type->getUnit();
                $balances[$type->getId()] = new Balance(
                    new LeaveTypeId($type->getId()),
                    $unit,
                    new LeaveAmount($unit, $entitlement->total()),
                    new LeaveAmount($unit, $used['taken']),
                    new LeaveAmount($unit, $used['planned']),
                    new LeaveAmount($unit, $used['pending']),
                    $entitlement->lines,
                    $entitlement,
                    $type->names(),
                );
            }

            return $balances;
        });
    }
}
