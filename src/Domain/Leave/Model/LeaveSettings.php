<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Model;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;

/**
 * A tenant's leave settings (contract section 6.6; spec 204 FR-002), versioned (a stale save is refused, 409). Leave
 * is off until enabled; enabling seeds the leave types. Approval chains and staffing minimums are stored here and
 * edited from S3 on.
 */
class LeaveSettings
{
    private bool $enabled = false;

    private bool $colleaguesSeeAbsences = true;

    private bool $medicalDocuments = false;

    private int $attachmentRetentionMonths = 12;

    private int $overtimeRatio = 100;

    private ?string $planningDeadline = null;

    private RoundingMode $rounding = RoundingMode::Nearest;

    private int $protectedCarryMonths = 18;

    /** @var array<mixed> group => list of 1–3 approver refs (S3) */
    private array $approvalChains = [];

    /** @var array<mixed> group => {min_present} | {max_off_percent} (S3) */
    private array $minStaffing = [];

    /** Optimistic lock (Doctrine `version`). */
    private int $version = 1;

    private ?string $updatedById = null;

    private function __construct(
        private string $tenantId,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Defaults of a tenant (leave off): never stored by a read.
     */
    public static function defaults(string $tenantId, DateTimeImmutable $at): self
    {
        return new self($tenantId, $at->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * Applies checked values.
     */
    public function change(LeaveSettingsInput $input, ?ActorRef $by, DateTimeImmutable $at): void
    {
        $this->enabled                   = $input->enabled; // @igor-ignore - entity state, not a service
        $this->rounding                  = $input->rounding; // @igor-ignore - entity state, not a service
        $this->protectedCarryMonths      = $input->protectedCarryMonths; // @igor-ignore - entity state, not a service
        $this->colleaguesSeeAbsences     = $input->colleaguesSeeAbsences; // @igor-ignore - entity state, not a service
        $this->medicalDocuments          = $input->medicalDocuments; // @igor-ignore - entity state, not a service
        $this->attachmentRetentionMonths = $input->attachmentRetentionMonths; // @igor-ignore - entity state, not a service
        $this->overtimeRatio             = $input->overtimeRatio; // @igor-ignore - entity state, not a service
        $this->planningDeadline          = $input->planningDeadline; // @igor-ignore - entity state, not a service
        $this->updatedById               = $by?->value; // @igor-ignore - entity state, not a service
        $this->updatedAt                 = $at->setTimezone(new DateTimeZone('UTC')); // @igor-ignore - entity state, not a service
    }

    /** The values as an input (the form). */
    public function input(): LeaveSettingsInput
    {
        return new LeaveSettingsInput($this->enabled, $this->rounding, $this->protectedCarryMonths, $this->colleaguesSeeAbsences, $this->medicalDocuments, $this->attachmentRetentionMonths, $this->overtimeRatio, $this->planningDeadline);
    }

    /** Tenant reference. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Whether leave is on for the tenant. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Pro-rata rounding. */
    public function rounding(): RoundingMode
    {
        return $this->rounding;
    }

    /** Months a protected carry-over lasts from the start of the receiving year. */
    public function protectedCarryMonths(): int
    {
        return $this->protectedCarryMonths;
    }

    /** Whether colleagues see *Absent* on the team calendar (S3). */
    public function colleaguesSeeAbsences(): bool
    {
        return $this->colleaguesSeeAbsences;
    }

    /** Whether medical absences may carry a document (S3). */
    public function medicalDocuments(): bool
    {
        return $this->medicalDocuments;
    }

    /** Months attachments are kept after the leave ends (S3). */
    public function attachmentRetentionMonths(): int
    {
        return $this->attachmentRetentionMonths;
    }

    /** Overtime to time-off ratio in percent (S3). */
    public function overtimeRatio(): int
    {
        return $this->overtimeRatio;
    }

    /** `MM-DD` vacation planning deadline (S3). */
    public function planningDeadline(): ?string
    {
        return $this->planningDeadline;
    }

    /**
     * Approval chains (S3).
     *
     * @return array<mixed>
     */
    public function approvalChains(): array
    {
        return $this->approvalChains;
    }

    /**
     * Staffing minimums (S3).
     *
     * @return array<mixed>
     */
    public function minStaffing(): array
    {
        return $this->minStaffing;
    }

    /** Version (optimistic lock). */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Who changed it last. */
    public function getUpdatedById(): ?string
    {
        return $this->updatedById;
    }

    /** When. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
