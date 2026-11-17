<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function mb_substr;

/**
 * One worker's day in one tenant's register (spec 198): the local date it started on (the schedule's zone), the
 * worker's name as the register keeps it (it survives account erasure until retention, art. 17.3.b) and the day's
 * schedule snapshot. INSERT only (database triggers); segments and chain links hang from it.
 */
class Workday
{
    /** @var array<string, mixed> */
    private array $schedule;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $workerId,
        private string $workerName,
        private DateTimeImmutable $localDate,
        DaySchedule $schedule,
        private DateTimeImmutable $createdAt,
        private ?int $legacyId = null,
    ) {
        $this->schedule = $schedule->toArray();
    }

    /**
     * A new workday.
     */
    public static function open(string $id, TenantRef $tenant, WorkerRef $worker, string $workerName, LocalDate $date, DaySchedule $schedule, DateTimeImmutable $at): self
    {
        return new self(Uuid::assert($id), $tenant->value, $worker->value, mb_substr($workerName, 0, WorkerProfile::NAME_MAX), $date->toDateTime(), $schedule, WorkdayRevision::utc($at));
    }

    /**
     * A workday migrated from Beacon (its integer id kept for format 1 links).
     */
    public static function migrated(string $id, TenantRef $tenant, WorkerRef $worker, string $workerName, LocalDate $date, DaySchedule $schedule, DateTimeImmutable $createdAt, int $legacyId): self
    {
        $workday           = self::open($id, $tenant, $worker, $workerName, $date, $schedule, $createdAt);
        $workday->legacyId = $legacyId;

        return $workday;
    }

    /** Row id (UUID). */
    public function getId(): string
    {
        return $this->id;
    }

    /** Tenant reference. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Worker reference. */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /** The worker's name as the register keeps it. */
    public function getWorkerName(): string
    {
        return $this->workerName;
    }

    /** The local date. */
    public function date(): LocalDate
    {
        return LocalDate::fromDateTime($this->localDate);
    }

    /** The schedule snapshot. */
    public function schedule(): DaySchedule
    {
        return DaySchedule::fromArray($this->schedule);
    }

    /** When the day was opened. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Beacon's integer id (format 1 chains only). */
    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }
}
