<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;

use function max;
use function min;
use function trim;

/**
 * A tenant's register settings (contract section 6.2): the register is **off** until a settings admin turns it on.
 * Updatable, checked against the `version` the admin saw (stale ⇒ 409).
 */
class RegisterSettings
{
    private bool $enabled = false;

    private bool $breaksCounted = false;

    private int $flexMinutes = 0;

    private int $minBreakMinutes = 0;

    private ?int $dailyOvertimeMinutes = null;

    private ?int $weeklyOvertimeMinutes = null;

    private bool $taskTimerLink = false;

    private bool $remindersAllowed = false;

    private bool $monthlySummaryMandatory = false;

    private bool $ipEvidence = false;

    /** @var array<string, string> */
    private array $ipNotices = [];

    private ?string $ipNoticeLocale = null;

    private int $retentionYears = RegisterSettingsInput::MIN_RETENTION_YEARS;

    private int $correctionWindowDays = 31;

    /** Optimistic lock (Doctrine `version`). */
    private int $version = 1;

    private ?string $updatedById = null;

    private function __construct(
        private string $tenantId,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Defaults of a tenant (register off).
     */
    public static function defaults(string $tenantId, DateTimeImmutable $at, int $retentionYears = RegisterSettingsInput::MIN_RETENTION_YEARS, int $correctionWindowDays = 31): self
    {
        $settings                       = new self($tenantId, WorkdayRevision::utc($at));
        $settings->retentionYears       = max(RegisterSettingsInput::MIN_RETENTION_YEARS, min(RegisterSettingsInput::MAX_RETENTION_YEARS, $retentionYears));
        $settings->correctionWindowDays = max(1, min(31, $correctionWindowDays));

        return $settings;
    }

    /**
     * Applies checked values.
     */
    public function change(RegisterSettingsInput $input, ?ActorRef $by, DateTimeImmutable $at): void
    {
        $this->enabled                 = $input->enabled;
        $this->breaksCounted           = $input->breaksCounted;
        $this->flexMinutes             = $input->flexMinutes;
        $this->minBreakMinutes         = $input->minBreakMinutes;
        $this->dailyOvertimeMinutes    = $input->dailyOvertimeMinutes;
        $this->weeklyOvertimeMinutes   = $input->weeklyOvertimeMinutes;
        $this->taskTimerLink           = $input->taskTimerLink;
        $this->remindersAllowed        = $input->remindersAllowed;
        $this->monthlySummaryMandatory = $input->monthlySummaryMandatory;
        $this->ipEvidence              = $input->ipEvidence;
        $this->ipNotices               = $input->ipNotices;
        $this->ipNoticeLocale          = $input->ipNoticeLocale;
        $this->retentionYears          = $input->retentionYears;
        $this->correctionWindowDays    = $input->correctionWindowDays;
        $this->updatedById             = $by?->value;
        $this->updatedAt               = WorkdayRevision::utc($at);
    }

    /**
     * The values as an input (the settings form starts from them).
     */
    public function toInput(): RegisterSettingsInput
    {
        return new RegisterSettingsInput(
            $this->enabled,
            $this->breaksCounted,
            $this->flexMinutes,
            $this->minBreakMinutes,
            $this->dailyOvertimeMinutes,
            $this->weeklyOvertimeMinutes,
            $this->taskTimerLink,
            $this->remindersAllowed,
            $this->monthlySummaryMandatory,
            $this->ipEvidence && $this->ipNoticeLocale !== null && trim($this->ipNotices[$this->ipNoticeLocale] ?? '') !== '',
            $this->ipNotices,
            $this->ipNoticeLocale,
            $this->retentionYears(),
            $this->correctionWindowDays,
        );
    }

    /** Tenant reference (primary key). */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Whether the register is on for the tenant. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Whether breaks count as worked time. */
    public function breaksCounted(): bool
    {
        return $this->breaksCounted;
    }

    /** Flexible band (minutes). */
    public function flexMinutes(): int
    {
        return $this->flexMinutes;
    }

    /** Informational minimum break on a day over six hours. */
    public function minBreakMinutes(): int
    {
        return $this->minBreakMinutes;
    }

    /** Daily overtime threshold (null: the schedule's expected time). */
    public function dailyOvertimeMinutes(): ?int
    {
        return $this->dailyOvertimeMinutes;
    }

    /** Weekly overtime threshold (null: the schedule's weekly target). */
    public function weeklyOvertimeMinutes(): ?int
    {
        return $this->weeklyOvertimeMinutes;
    }

    /** Whether clocking out stops the task timer (TimeTrack bridge). */
    public function taskTimerLink(): bool
    {
        return $this->taskTimerLink;
    }

    /** Whether workers may opt in to reminders. */
    public function remindersAllowed(): bool
    {
        return $this->remindersAllowed;
    }

    /** Whether the monthly summary cannot be opted out of. */
    public function monthlySummaryMandatory(): bool
    {
        return $this->monthlySummaryMandatory;
    }

    /**
     * Whether IP prefixes are recorded (only with the notice text in the notice locale).
     */
    public function ipEvidence(): bool
    {
        return $this->ipEvidence && $this->ipNoticeLocale !== null && trim($this->ipNotices[$this->ipNoticeLocale] ?? '') !== '';
    }

    /**
     * @return array<string, string>
     */
    public function ipNotices(): array
    {
        return $this->ipNotices;
    }

    /**
     * The notice shown to a reader: their locale's text, else the notice locale's.
     */
    public function ipNoticeFor(string $locale): ?string
    {
        $text = $this->ipNotices[$locale] ?? ($this->ipNoticeLocale === null ? null : ($this->ipNotices[$this->ipNoticeLocale] ?? null));

        return $text === null || trim($text) === '' ? null : $text;
    }

    /** Locale of the reference notice. */
    public function ipNoticeLocale(): ?string
    {
        return $this->ipNoticeLocale;
    }

    /** Retention in years, never below four whatever is stored. */
    public function retentionYears(): int
    {
        return max(RegisterSettingsInput::MIN_RETENTION_YEARS, min(RegisterSettingsInput::MAX_RETENTION_YEARS, $this->retentionYears));
    }

    /** Days back a worker may ask for a correction. */
    public function correctionWindowDays(): int
    {
        return $this->correctionWindowDays;
    }

    /** Version the next update is checked against. */
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
