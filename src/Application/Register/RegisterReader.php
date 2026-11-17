<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterRules;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function array_map;

/**
 * Reads register days (spec 198 FR-010 / FR-020): workdays, then segments, chain links and correction requests of all
 * of them in one query each.
 */
final readonly class RegisterReader implements RegisterReaderInterface
{
    public function __construct(
        private RegisterStoreInterface $store,
        private RegisterRules $rules = new RegisterRules(),
    ) {
    }

    /**
     * One worker's days by date.
     *
     * @return array<string, DayRecord>
     */
    public function worker(TenantRef $tenant, WorkerRef $worker, LocalDate $from, LocalDate $to, DateTimeImmutable $now): array
    {
        $records = [];
        foreach ($this->records($this->store->workdaysBetween($tenant, $worker, $from, $to), $now) as $record) {
            $records[$record->workday->date()->value] = $record;
        }

        return $records;
    }

    /**
     * Every worker's days by worker and date.
     *
     * @param list<WorkerRef>|null $workers
     *
     * @return array<string, array<string, DayRecord>>
     */
    public function tenant(TenantRef $tenant, LocalDate $from, LocalDate $to, DateTimeImmutable $now, ?array $workers = null): array
    {
        $byWorker = [];
        foreach ($this->records($this->store->tenantWorkdaysBetween($tenant, $from, $to, $workers), $now) as $record) {
            $byWorker[$record->workday->getWorkerId()][$record->workday->date()->value] = $record;
        }

        return $byWorker;
    }

    /**
     * @param list<Workday> $workdays
     *
     * @return list<DayRecord>
     */
    public function records(array $workdays, DateTimeImmutable $now): array
    {
        if ($workdays === []) {
            return [];
        }
        $ids       = array_map(static fn (Workday $workday): string => $workday->getId(), $workdays);
        $segments  = $this->store->segmentsOf($ids);
        $revisions = $this->store->revisionsOf($ids);
        $requests  = $this->store->correctionsOf($ids);

        return array_map(fn (Workday $workday): DayRecord => DayRecordBuilder::build(
            $workday,
            $segments[$workday->getId()] ?? [],
            $revisions[$workday->getId()] ?? [],
            $requests[$workday->getId()] ?? [],
            $now,
            $this->rules,
        ), $workdays);
    }

    /**
     * One workday's record.
     */
    public function one(Workday $workday, DateTimeImmutable $now): DayRecord
    {
        return $this->records([$workday], $now)[0];
    }
}
