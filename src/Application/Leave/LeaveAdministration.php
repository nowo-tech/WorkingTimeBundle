<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\AfterCommitEvents;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Application\Shared\WriteConflictException;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Event\LeaveAllowanceAdjusted;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeId;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveAdministrationInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function count;
use function sprintf;

/**
 * Leave administration (contract sections 7 and 9.3; Beacon's `LeaveAdministration` and `LeaveSettingsEditor`): each
 * write is one write unit; audit rows and events go out after the commit (a replayed attempt records nothing twice).
 * Also runs the daily carry-over expiry ({@see self::expireCarryOvers()}, no actor: the console job).
 */
final readonly class LeaveAdministration implements LeaveAdministrationInterface
{
    /** JSON error code of a type edit on a version that is no longer current. */
    public const TYPE_STALE = 'leave_type_stale';

    /** Lines expired per batch of the daily job. */
    public const EXPIRY_BATCH = 500;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private LeaveAccess $access,
        private LeaveStoreInterface $store,
        private LeaveTypeSeeder $seeder,
        private WorkerDirectoryInterface $directory,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private WorkingTimeAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * See the implemented interface.
     */
    public function types(): array
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);

        return $this->runner->read(fn (): array => $this->store->types($context->tenant)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function type(string $id): LeaveType
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);

        return $this->runner->read(fn (): LeaveType => $this->ownType($context->tenant, $id)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function saveType(?string $id, LeaveTypeInput $input, ?int $expectedVersion = null): LeaveType
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);
        try {
            $type = $this->runner->write(function () use ($context, $id, $input, $expectedVersion): LeaveType { // @igor-ignore - the runner calls this closure at once and keeps nothing
                if ($id === null) {
                    $type = LeaveType::create($this->ids->generate(), $context->tenant->value, $input, $context->locale, $this->clock->now());
                    $this->store->addType($type); // @igor-ignore - persistence call on a stateless collaborator

                    return $type;
                }
                $type = $this->ownType($context->tenant, $id, true);
                if ($expectedVersion !== null && $type->getVersion() !== $expectedVersion) {
                    throw new WriteConflictException(self::TYPE_STALE);
                }
                if ($type->getUnit() !== $input->unit && $this->store->typeInUse($context->tenant, $type->getId())) {
                    throw new LeaveRefusedException('leave.type.error.unit_in_use');
                }
                $type->change($input, $context->locale);

                return $type;
            });
        } catch (StaleRowException $e) {
            throw new WriteConflictException(self::TYPE_STALE, $e);
        }
        $this->record('working_time.leave_type_changed', $context->tenant, $context, null, ['type' => $type->getId(), 'change' => $id === null ? 'created' : 'changed']);

        return $type;
    }

    /**
     * See the implemented interface.
     */
    public function archiveType(string $id, bool $archived): void
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);
        $this->runner->write(function () use ($context, $id, $archived): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ownType($context->tenant, $id, true)->setArchived($archived);
        });
        $this->record('working_time.leave_type_changed', $context->tenant, $context, null, ['type' => $id, 'change' => $archived ? 'archived' : 'restored']);
    }

    /**
     * See the implemented interface.
     */
    public function seedTypes(): int
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);
        // A racing seed (another click, another tab) inserts the same codes: the unique (tenant, code) key refuses
        // ours and the second attempt finds them seeded (audit TD-M1).
        $added = WriteConflictException::writeOnceMore($this->runner, fn (): int => $this->seeder->seed($context->tenant, $context->locale, $this->clock->now(), $context->actor), 'leave_seed_conflict'); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $this->record('working_time.leave_type_changed', $context->tenant, $context, null, ['change' => 'seeded', 'count' => $added]);

        return $added;
    }

    /**
     * See the implemented interface.
     */
    public function policies(int $year): array
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);

        return $this->runner->read(fn (): array => $this->store->policies($context->tenant, $year)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function addPolicy(string $typeId, ScheduleScope $scope, ?string $target, int $year, int $amount, bool $agreementEquivalent = false, ?string $note = null): AllowancePolicy
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);
        if ($scope === ScheduleScope::Worker) {
            $this->access->member($context->tenant, new WorkerRef((string) $target));
            $this->refuseOwn($context, new WorkerRef((string) $target));
        }
        if ($scope === ScheduleScope::Group && !$this->knownGroup($context->tenant, (string) $target)) {
            throw new LeaveRefusedException('leave.allowance.error.target');
        }
        $policy = $this->runner->write(function () use ($context, $typeId, $scope, $target, $year, $amount, $agreementEquivalent, $note): AllowancePolicy { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $type = $this->ownType($context->tenant, $typeId, true);
            if ($type->getSource() !== AllowanceSource::Allowance) {
                throw new LeaveRefusedException('leave.allowance.error.type');
            }
            $policy = AllowancePolicy::create($this->ids->generate(), $type, $scope, $target, $year, $amount, $agreementEquivalent, $note, $this->clock->now(), $context->actor);
            $this->store->addPolicy($policy); // @igor-ignore - persistence call on a stateless collaborator

            return $policy;
        });
        $this->record('working_time.leave_allowance_changed', $context->tenant, $context, $scope === ScheduleScope::Worker ? new WorkerRef((string) $target) : null, ['type' => $typeId, 'scope' => $scope->value, 'year' => $year, 'amount' => $amount, 'change' => 'added']);

        return $policy;
    }

    /**
     * See the implemented interface.
     */
    public function removePolicy(string $id): void
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage);
        $policy  = $this->runner->write(function () use ($context, $id): AllowancePolicy { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $policy = $this->store->policy($context->tenant, $id) ?? throw new NotAvailableException();
            $this->store->removePolicy($policy); // @igor-ignore - persistence call on a stateless collaborator

            return $policy;
        });
        $this->record('working_time.leave_allowance_changed', $context->tenant, $context, null, ['type' => $policy->getLeaveTypeId(), 'scope' => $policy->getScope()->value, 'year' => $policy->getYear(), 'change' => 'removed']);
    }

    /**
     * See the implemented interface.
     */
    public function lines(WorkerRef $worker, int $year): array
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage, $worker);

        return $this->runner->read(fn (): array => $this->store->lines($context->tenant, $worker, $year)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * See the implemented interface.
     */
    public function addLine(string $typeId, WorkerRef $worker, int $year, AllowanceLineKind $kind, int $amount, string $reason, ?LocalDate $expiresOn = null): AllowanceLine
    {
        $context = $this->access->context(WorkingTimePermission::LeaveManage, $worker);
        $this->refuseOwn($context, $worker);
        $profile = $this->access->member($context->tenant, $worker);
        $events  = new AfterCommitEvents();
        $line    = $this->runner->write(function () use ($context, $profile, $typeId, $worker, $year, $kind, $amount, $reason, $expiresOn, $events): AllowanceLine { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $events->clear();
            $type = $this->ownType($context->tenant, $typeId, true);
            if ($type->getSource() !== AllowanceSource::Allowance) {
                throw new LeaveRefusedException('leave.allowance.error.type');
            }
            if ($kind === AllowanceLineKind::CarryOver && !$type->allowsCarryOver()) {
                throw new LeaveRefusedException('leave.allowance.error.carry_off');
            }
            if ($kind === AllowanceLineKind::CarryOver && $type->getCarryOverMax() !== null && $amount > $type->getCarryOverMax()) {
                throw new LeaveRefusedException('leave.allowance.error.carry_max', ['%max%' => $type->getUnit()->format($type->getCarryOverMax())]);
            }
            $expiresOn ??= match ($kind) {
                AllowanceLineKind::ProtectedCarryOver => LocalDate::fromDateTime((new LocalDate(sprintf('%04d-01-01', $year)))->toDateTime()->modify(sprintf('+%d months -1 day', $context->settings->protectedCarryMonths()))),
                AllowanceLineKind::CarryOver          => $type->getCarryOverExpiry() === null ? null : LocalDate::tryParse(sprintf('%04d-%s', $year, $type->getCarryOverExpiry())),
                AllowanceLineKind::Adjustment         => null,
            };
            if ($amount < 0) {
                // An adjustment never takes the entitlement below zero (audit TD-L5); read under the type's lock.
                $entitled = AllowanceResolver::entitlement($context->settings->rounding(), $type, $profile, $year, $this->store->policies($context->tenant, $year), $this->store->lines($context->tenant, $worker, $year), LocalDate::fromDateTime($this->clock->now()))->total();
                if ($entitled + $amount < 0) {
                    throw new LeaveRefusedException('leave.allowance.error.negative', ['%entitled%' => $type->getUnit()->format($entitled)]);
                }
            }
            $line = AllowanceLine::create($this->ids->generate(), $type, $worker, $year, $kind, $amount, $reason, $expiresOn, $this->clock->now(), $context->actor);
            $this->store->addLine($line); // @igor-ignore - persistence call on a stateless collaborator
            $events->add(new LeaveAllowanceAdjusted($context->tenant, $worker, new LeaveTypeId($type->getId()), $year, $kind, $amount));

            return $line;
        });
        $events->dispatch($this->events);
        $this->record('working_time.leave_adjusted', $context->tenant, $context, $worker, ['type' => $typeId, 'year' => $year, 'kind' => $kind->value, 'amount' => $amount, 'line' => $line->getId()]);

        return $line;
    }

    /**
     * See the implemented interface.
     */
    public function settings(): array
    {
        $context = $this->access->context(WorkingTimePermission::ManageSettings, null, false);
        $stored  = $this->runner->read(fn (): ?LeaveSettings => $this->store->settings($context->tenant)); // @igor-ignore - the runner calls this closure at once and keeps nothing

        return [$stored ?? $context->settings, $stored?->getVersion() ?? 0];
    }

    /**
     * See the implemented interface.
     */
    public function updateSettings(LeaveSettingsInput $input, int $expectedVersion): LeaveSettings
    {
        $context = $this->access->context(WorkingTimePermission::ManageSettings, null, false);
        if ($input->enabled && !$context->register->isEnabled()) {
            throw new LeaveRefusedException('leave.settings.error.register_off');
        }
        try {
            [$settings, $seeded] = $this->runner->write(function () use ($context, $input, $expectedVersion): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $now    = $this->clock->now();
                $stored = $this->store->settings($context->tenant);
                if (($stored?->getVersion() ?? 0) !== $expectedVersion) {
                    throw new StaleLeaveSettingsException();
                }
                $settings = $stored ?? LeaveSettings::defaults($context->tenant->value, $now);
                $enabling = $input->enabled && !$settings->isEnabled();
                $settings->change($input, $context->actor, $now);
                if (!$stored instanceof LeaveSettings) {
                    $this->store->addSettings($settings); // @igor-ignore - persistence call on a stateless collaborator
                }
                $seeded = $enabling ? $this->seeder->seed($context->tenant, $context->locale, $now, $context->actor) : 0;
                $this->runner->flush();

                return [$settings, $seeded];
            });
        } catch (StaleRowException|DuplicateRowException $e) {
            throw new StaleLeaveSettingsException($e);
        }
        $this->record('working_time.leave_settings_changed', $context->tenant, $context, null, ['enabled' => $input->enabled, 'rounding' => $input->rounding->value, 'seeded' => $seeded]);

        return $settings;
    }

    /**
     * Marks the carry-overs past their expiry (the daily job, contract section 12): one batch per write unit, one
     * audit row per tenant with the count. Returns how many lines expired.
     */
    public function expireCarryOvers(): int
    {
        $now     = $this->clock->now();
        $today   = LocalDate::fromDateTime($now);
        $total   = 0;
        $tenants = [];
        do {
            $batch = $this->runner->write(function () use ($today, $now, &$tenants): int { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $lines = $this->store->expiringLines($today, self::EXPIRY_BATCH);
                foreach ($lines as $line) {
                    $line->expire($now);
                    $tenants[$line->getTenantId()] = ($tenants[$line->getTenantId()] ?? 0) + 1;
                }

                return count($lines);
            });
            $total += $batch;
        } while ($batch === self::EXPIRY_BATCH);
        foreach ($tenants as $tenant => $count) {
            $this->audit->record(new AuditEntry('working_time.leave_carry_over_expired', new TenantRef($tenant), null, null, ['count' => $count]));
        }

        return $total;
    }

    /**
     * The tenant's type, else 404; `$lock` serialises the writes of one type (its unit against its allowances).
     *
     * @throws NotAvailableException
     */
    private function ownType(TenantRef $tenant, string $id, bool $lock = false): LeaveType
    {
        $type = $this->store->type($tenant, $id, $lock);
        if (!$type instanceof LeaveType || $type->getTenantId() !== $tenant->value) {
            throw new NotAvailableException();
        }

        return $type;
    }

    /**
     * Nobody changes their own allowance (the "never for oneself" rule of contract section 13).
     *
     * @throws LeaveRefusedException `leave.allowance.error.self`
     */
    private function refuseOwn(LeaveContext $context, WorkerRef $worker): void
    {
        if ($worker->isActor($context->actor)) {
            throw new LeaveRefusedException('leave.allowance.error.self');
        }
    }

    private function knownGroup(TenantRef $tenant, string $group): bool
    {
        foreach ($this->directory->groups($tenant) as [$ref]) {
            if ($ref->value === $group) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function record(string $key, TenantRef $tenant, LeaveContext $context, ?WorkerRef $subject, array $values): void
    {
        $this->audit->record(new AuditEntry($key, $tenant, $context->actor, $subject, $values));
    }
}
