<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave\Port;

use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Persistence of the leave foundations (implemented on the bundle's entity manager). Every lookup is scoped by the
 * tenant and re-checks the references with `===` (rule LQ).
 */
interface LeaveStoreInterface
{
    /** The tenant's settings (null: never saved). */
    public function settings(TenantRef $tenant): ?LeaveSettings;

    /** Persists new settings. */
    public function addSettings(LeaveSettings $settings): void;

    /**
     * The tenant's types by sort order, then name.
     *
     * @return list<LeaveType>
     */
    public function types(TenantRef $tenant): array;

    /** One type of the tenant. */
    public function type(TenantRef $tenant, string $id): ?LeaveType;

    /** The tenant's seeded type of a code. */
    public function typeBySeed(TenantRef $tenant, string $seedCode): ?LeaveType;

    /** Whether a type is referenced (S2: by a policy or an allowance line of any year; S3 adds requests). */
    public function typeInUse(TenantRef $tenant, string $typeId): bool;

    /** Persists a new type. */
    public function addType(LeaveType $type): void;

    /**
     * The tenant's policies of a year, newest first.
     *
     * @return list<AllowancePolicy>
     */
    public function policies(TenantRef $tenant, int $year): array;

    /** One policy of the tenant. */
    public function policy(TenantRef $tenant, string $id): ?AllowancePolicy;

    /** Persists a new policy. */
    public function addPolicy(AllowancePolicy $policy): void;

    /** Removes a policy (configuration: the balances follow at once; taken days never change). */
    public function removePolicy(AllowancePolicy $policy): void;

    /**
     * A worker's lines of a year, oldest first.
     *
     * @return list<AllowanceLine>
     */
    public function lines(TenantRef $tenant, WorkerRef $worker, int $year): array;

    /** Persists a new line. */
    public function addLine(AllowanceLine $line): void;

    /**
     * Lines of every tenant whose expiry date is before the date and that are not marked expired yet.
     *
     * @return list<AllowanceLine>
     */
    public function expiringLines(LocalDate $before, int $limit): array;
}
