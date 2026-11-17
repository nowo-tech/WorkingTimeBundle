<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;

/**
 * A worker's own register preferences (spec 198 FR-025 / FR-026): reminders (off by default, only when the tenant
 * allows them) and the monthly summary (on by default; mandatory when the tenant says so). Deleted on erasure.
 */
class WorkerPreference
{
    private bool $reminders = false;

    private bool $monthlySummary = true;

    private function __construct(
        private string $workerId,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Defaults of a worker.
     */
    public static function defaults(string $workerId, DateTimeImmutable $at): self
    {
        return new self($workerId, WorkdayRevision::utc($at));
    }

    /**
     * Changes the preferences.
     */
    public function change(bool $reminders, bool $monthlySummary, DateTimeImmutable $at): void
    {
        $this->reminders      = $reminders;
        $this->monthlySummary = $monthlySummary;
        $this->updatedAt      = WorkdayRevision::utc($at);
    }

    /** Worker reference (primary key). */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /** Whether the worker wants clock reminders. */
    public function wantsReminders(): bool
    {
        return $this->reminders;
    }

    /** Whether the worker wants the monthly summary. */
    public function wantsMonthlySummary(): bool
    {
        return $this->monthlySummary;
    }

    /** When it last changed. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
