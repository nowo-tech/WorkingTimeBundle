<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Reads days of the register as {@see DayRecord}s (contract section 7), four queries for any number of days, always
 * inside one tenant. No permission check: callers pass the gate first.
 */
interface RegisterReaderInterface
{
    /**
     * One worker's days between two dates (inclusive).
     *
     * @return array<string, DayRecord> `Y-m-d` => record
     */
    public function worker(TenantRef $tenant, WorkerRef $worker, LocalDate $from, LocalDate $to, DateTimeImmutable $now): array;

    /**
     * Every worker's days of the tenant between two dates (optionally some workers).
     *
     * @param list<WorkerRef>|null $workers
     *
     * @return array<string, array<string, DayRecord>> worker ref => (`Y-m-d` => record)
     */
    public function tenant(TenantRef $tenant, LocalDate $from, LocalDate $to, DateTimeImmutable $now, ?array $workers = null): array;

    /**
     * Records of workdays.
     *
     * @param list<Workday> $workdays
     *
     * @return list<DayRecord>
     */
    public function records(array $workdays, DateTimeImmutable $now): array;
}
