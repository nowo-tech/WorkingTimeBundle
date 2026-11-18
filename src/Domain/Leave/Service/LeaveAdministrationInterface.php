<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Service;

use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Leave administration of the current tenant (contract section 7; spec 204 FR-002 – FR-007): types (seeded, editable,
 * archivable), allowance policies, allowance lines (adjustments, carry-overs, protected carry-overs) and the settings.
 * Types, policies and lines need `leave_manage`; the settings need `manage_settings`. Every method resolves the tenant
 * and the permission first ({@see NotAvailableException} 404, {@see AccessDeniedException} 403); a refused change is a
 * {@see LeaveRefusedException}. Each write is audited (`working_time.leave_*`, ids and amounts, never a reason).
 */
interface LeaveAdministrationInterface
{
    /**
     * The tenant's types (archived included), by sort order.
     *
     * @return list<LeaveType>
     */
    public function types(): array;

    /** One type of the tenant. */
    public function type(string $id): LeaveType;

    /**
     * Creates (`$id` null) or changes a type; the unit of a type in use cannot change; with `$expectedVersion` an
     * edit of a newer version is a 409.
     */
    public function saveType(?string $id, LeaveTypeInput $input, ?int $expectedVersion = null): LeaveType;

    /** Archives or restores a type (never deleted while referenced). */
    public function archiveType(string $id, bool $archived): void;

    /** Seeds the missing default types (and the vacation default of the current year); returns how many were added. */
    public function seedTypes(): int;

    /**
     * The tenant's policies of a year, newest first.
     *
     * @return list<AllowancePolicy>
     */
    public function policies(int $year): array;

    /** Adds a policy (tenant default, a group's or a worker's). */
    public function addPolicy(string $typeId, ScheduleScope $scope, ?string $target, int $year, int $amount, bool $agreementEquivalent = false, ?string $note = null): AllowancePolicy;

    /** Removes a policy. */
    public function removePolicy(string $id): void;

    /**
     * A worker's lines of a year.
     *
     * @return list<AllowanceLine>
     */
    public function lines(WorkerRef $worker, int $year): array;

    /**
     * Adds a line; a carry-over without a date expires on the type's `carry_over_expiry` of the year, a protected one
     * `protected_carry_months` after 1 January of the year.
     */
    public function addLine(string $typeId, WorkerRef $worker, int $year, AllowanceLineKind $kind, int $amount, string $reason, ?LocalDate $expiresOn = null): AllowanceLine;

    /**
     * The tenant's leave settings (defaults, leave off, when never saved) and the version to post back (0: never saved).
     *
     * @return array{LeaveSettings, int}
     */
    public function settings(): array;

    /** Saves the settings against the version read; enabling seeds the types. */
    public function updateSettings(LeaveSettingsInput $input, int $expectedVersion): LeaveSettings;
}
