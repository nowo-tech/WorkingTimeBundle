<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ChainAnchor;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkerPreference;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_string;

/**
 * The register's persistence on the bundle's entity manager. Protected rows only get `persist()` (no update or delete
 * path; the triggers refuse them anyway); every lookup by a host reference re-checks it with `===` in PHP (rule LQ:
 * the column collation is exact on every supported database, this is the second layer).
 */
final readonly class DoctrineRegisterStore implements RegisterStoreInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * See the implemented interface.
     */
    public function settings(TenantRef $tenant): ?RegisterSettings
    {
        $settings = $this->em()->find(RegisterSettings::class, $tenant->value);

        return $settings !== null && $settings->getTenantId() === $tenant->value ? $settings : null;
    }

    /**
     * See the implemented interface.
     */
    public function allSettings(): array
    {
        /** @var list<RegisterSettings> $all */
        $all = $this->em()->createQueryBuilder()->select('s')->from(RegisterSettings::class, 's')->orderBy('s.tenantId')->getQuery()->getResult();

        return $all;
    }

    /**
     * See the implemented interface.
     */
    public function addSettings(RegisterSettings $settings): void
    {
        $this->em()->persist($settings);
    }

    /**
     * See the implemented interface.
     */
    public function preference(WorkerRef $worker): ?WorkerPreference
    {
        $preference = $this->em()->find(WorkerPreference::class, $worker->value);

        return $preference !== null && $preference->getWorkerId() === $worker->value ? $preference : null;
    }

    /**
     * See the implemented interface.
     */
    public function addPreference(WorkerPreference $preference): void
    {
        $this->em()->persist($preference);
    }

    /**
     * See the implemented interface.
     */
    public function removePreference(WorkerRef $worker): void
    {
        $preference = $this->preference($worker);
        if ($preference instanceof WorkerPreference) {
            $this->em()->remove($preference);
        }
    }

    /**
     * See the implemented interface.
     */
    public function workday(string $id): ?Workday
    {
        return $this->em()->find(Workday::class, $id);
    }

    /**
     * See the implemented interface.
     */
    public function workdayOn(TenantRef $tenant, WorkerRef $worker, LocalDate $date): ?Workday
    {
        $workday = $this->em()->getRepository(Workday::class)->findOneBy(['tenantId' => $tenant->value, 'workerId' => $worker->value, 'localDate' => $date->toDateTime()]);

        return $workday !== null && $workday->getTenantId() === $tenant->value && $workday->getWorkerId() === $worker->value ? $workday : null;
    }

    /**
     * See the implemented interface.
     */
    public function latestWorkdays(TenantRef $tenant, WorkerRef $worker, int $limit): array
    {
        return $this->own($tenant, $worker, $this->em()->getRepository(Workday::class)->findBy(['tenantId' => $tenant->value, 'workerId' => $worker->value], ['localDate' => 'DESC'], $limit));
    }

    /**
     * See the implemented interface.
     */
    public function workdaysBetween(TenantRef $tenant, WorkerRef $worker, LocalDate $from, LocalDate $to): array
    {
        /** @var list<Workday> $rows */
        $rows = $this->em()->createQueryBuilder()->select('w')->from(Workday::class, 'w')
            ->where('w.tenantId = :tenant AND w.workerId = :worker AND w.localDate BETWEEN :from AND :to')
            ->setParameter('tenant', $tenant->value)->setParameter('worker', $worker->value)
            ->setParameter('from', $from->toDateTime(), 'date_immutable')->setParameter('to', $to->toDateTime(), 'date_immutable')
            ->orderBy('w.localDate')->getQuery()->getResult();

        return $this->own($tenant, $worker, $rows);
    }

    /**
     * See the implemented interface.
     */
    public function tenantWorkdaysBetween(TenantRef $tenant, LocalDate $from, LocalDate $to, ?array $workers = null): array
    {
        if ($workers === []) {
            return [];
        }
        $qb = $this->em()->createQueryBuilder()->select('w')->from(Workday::class, 'w')
            ->where('w.tenantId = :tenant AND w.localDate BETWEEN :from AND :to')
            ->setParameter('tenant', $tenant->value)
            ->setParameter('from', $from->toDateTime(), 'date_immutable')->setParameter('to', $to->toDateTime(), 'date_immutable')
            ->orderBy('w.workerId')->addOrderBy('w.localDate');
        $refs = $workers === null ? null : array_map(static fn (WorkerRef $worker): string => $worker->value, $workers);
        if ($refs !== null) {
            $qb->andWhere('w.workerId IN (:workers)')->setParameter('workers', $refs);
        }
        /** @var list<Workday> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (Workday $workday): bool => $workday->getTenantId() === $tenant->value && ($refs === null || in_array($workday->getWorkerId(), $refs, true))));
    }

    /**
     * See the implemented interface.
     */
    public function workdaysOfWorker(WorkerRef $worker): array
    {
        /** @var list<Workday> $rows */
        $rows = $this->em()->getRepository(Workday::class)->findBy(['workerId' => $worker->value], ['tenantId' => 'ASC', 'localDate' => 'ASC']);

        return array_values(array_filter($rows, static fn (Workday $workday): bool => $workday->getWorkerId() === $worker->value));
    }

    /**
     * See the implemented interface.
     */
    public function addWorkday(Workday $workday): void
    {
        $this->em()->persist($workday);
    }

    /**
     * See the implemented interface.
     */
    public function segment(string $id): ?WorkSegment
    {
        return $this->em()->find(WorkSegment::class, $id);
    }

    /**
     * See the implemented interface.
     */
    public function addSegment(WorkSegment $segment): void
    {
        $this->em()->persist($segment);
    }

    /**
     * See the implemented interface.
     */
    public function segmentsOf(array $workdayIds): array
    {
        $grouped = [];
        if ($workdayIds === []) {
            return $grouped;
        }
        foreach ($this->em()->getRepository(WorkSegment::class)->findBy(['workdayId' => $workdayIds], ['startedAt' => 'ASC']) as $segment) {
            $grouped[$segment->getWorkdayId()][] = $segment;
        }

        return $grouped;
    }

    /**
     * See the implemented interface.
     */
    public function segmentsById(array $ids): array
    {
        $byId = [];
        if ($ids === []) {
            return $byId;
        }
        foreach ($this->em()->getRepository(WorkSegment::class)->findBy(['id' => $ids]) as $segment) {
            $byId[$segment->getId()] = $segment;
        }

        return $byId;
    }

    /**
     * See the implemented interface.
     */
    public function revisionsOf(array $workdayIds): array
    {
        $grouped = [];
        if ($workdayIds === []) {
            return $grouped;
        }
        foreach ($this->em()->getRepository(WorkdayRevision::class)->findBy(['workdayId' => $workdayIds], ['seq' => 'ASC']) as $revision) {
            $grouped[$revision->getWorkdayId()][] = $revision;
        }

        return $grouped;
    }

    /**
     * See the implemented interface.
     */
    public function chainHead(TenantRef $tenant, WorkerRef $worker): ?WorkdayRevision
    {
        $head = $this->em()->getRepository(WorkdayRevision::class)->findOneBy(['tenantId' => $tenant->value, 'workerId' => $worker->value], ['seq' => 'DESC']);

        return $head !== null && $head->getTenantId() === $tenant->value && $head->getWorkerId() === $worker->value ? $head : null;
    }

    /**
     * See the implemented interface.
     */
    public function revisionByRequestKey(TenantRef $tenant, WorkerRef $worker, string $requestKey): ?WorkdayRevision
    {
        $revision = $this->em()->getRepository(WorkdayRevision::class)->findOneBy(['tenantId' => $tenant->value, 'workerId' => $worker->value, 'requestKey' => $requestKey]);

        return $revision !== null && $revision->getTenantId() === $tenant->value && $revision->getWorkerId() === $worker->value && $revision->getRequestKey() === $requestKey ? $revision : null;
    }

    /**
     * See the implemented interface.
     */
    public function revision(string $id): ?WorkdayRevision
    {
        return $this->em()->find(WorkdayRevision::class, $id);
    }

    /**
     * See the implemented interface.
     */
    public function addRevision(WorkdayRevision $revision): void
    {
        $this->em()->persist($revision);
    }

    /**
     * See the implemented interface.
     */
    public function chains(?TenantRef $tenant = null, ?WorkerRef $worker = null): array
    {
        $qb = $this->em()->createQueryBuilder()->select('DISTINCT r.tenantId AS tenant, r.workerId AS worker')->from(WorkdayRevision::class, 'r')
            ->orderBy('r.tenantId')->addOrderBy('r.workerId');
        if ($tenant instanceof TenantRef) {
            $qb->andWhere('r.tenantId = :tenant')->setParameter('tenant', $tenant->value);
        }
        if ($worker instanceof WorkerRef) {
            $qb->andWhere('r.workerId = :worker')->setParameter('worker', $worker->value);
        }
        $chains = [];
        /** @var list<array{tenant: mixed, worker: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            if (is_string($row['tenant']) && is_string($row['worker']) && (!$tenant instanceof TenantRef || $row['tenant'] === $tenant->value) && (!$worker instanceof WorkerRef || $row['worker'] === $worker->value)) {
                $chains[] = [new TenantRef($row['tenant']), new WorkerRef($row['worker'])];
            }
        }

        return $chains;
    }

    /**
     * See the implemented interface.
     */
    public function chainBatch(TenantRef $tenant, WorkerRef $worker, int $afterSeq, int $limit): array
    {
        /** @var list<WorkdayRevision> $rows */
        $rows = $this->em()->createQueryBuilder()->select('r')->from(WorkdayRevision::class, 'r')
            ->where('r.tenantId = :tenant AND r.workerId = :worker AND r.seq > :after')
            ->setParameter('tenant', $tenant->value)->setParameter('worker', $worker->value)->setParameter('after', $afterSeq)
            ->orderBy('r.seq')->setMaxResults($limit)->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (WorkdayRevision $revision): bool => $revision->getTenantId() === $tenant->value && $revision->getWorkerId() === $worker->value));
    }

    /**
     * See the implemented interface.
     */
    public function anchors(TenantRef $tenant, WorkerRef $worker): array
    {
        $rows = $this->em()->getRepository(ChainAnchor::class)->findBy(['tenantId' => $tenant->value, 'workerId' => $worker->value], ['firstKeptSeq' => 'ASC']);

        return array_values(array_filter($rows, static fn (ChainAnchor $anchor): bool => $anchor->getTenantId() === $tenant->value && $anchor->getWorkerId() === $worker->value));
    }

    /**
     * See the implemented interface.
     */
    public function correction(string $id): ?CorrectionRequest
    {
        return $this->em()->find(CorrectionRequest::class, $id);
    }

    /**
     * See the implemented interface.
     */
    public function addCorrection(CorrectionRequest $request): void
    {
        $this->em()->persist($request);
    }

    /**
     * See the implemented interface.
     */
    public function correctionsOf(array $workdayIds): array
    {
        $grouped = [];
        if ($workdayIds === []) {
            return $grouped;
        }
        foreach ($this->em()->getRepository(CorrectionRequest::class)->findBy(['workdayId' => $workdayIds], ['createdAt' => 'ASC']) as $request) {
            $grouped[$request->getWorkdayId()][] = $request;
        }

        return $grouped;
    }

    /**
     * See the implemented interface.
     */
    public function pendingCorrections(TenantRef $tenant, ?array $workers = null, int $limit = 200): array
    {
        if ($workers === []) {
            return [];
        }
        $refs     = $workers === null ? null : array_map(static fn (WorkerRef $worker): string => $worker->value, $workers);
        $criteria = ['tenantId' => $tenant->value, 'status' => CorrectionStatus::Pending];
        if ($refs !== null) {
            $criteria['workerId'] = $refs;
        }
        $rows = $this->em()->getRepository(CorrectionRequest::class)->findBy($criteria, ['createdAt' => 'ASC'], $limit);

        return array_values(array_filter($rows, static fn (CorrectionRequest $request): bool => $request->getTenantId() === $tenant->value && ($refs === null || in_array($request->getWorkerId(), $refs, true))));
    }

    /**
     * See the implemented interface.
     */
    public function scheduleCandidates(TenantRef $tenant, LocalDate $until): array
    {
        /** @var list<ScheduleAssignment> $rows */
        $rows = $this->em()->createQueryBuilder()->select('s')->from(ScheduleAssignment::class, 's')
            ->where('s.tenantId = :tenant AND s.validFrom <= :until')
            ->setParameter('tenant', $tenant->value)->setParameter('until', $until->toDateTime(), 'date_immutable')
            ->orderBy('s.validFrom', 'DESC')->addOrderBy('s.createdAt', 'DESC')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (ScheduleAssignment $row): bool => $row->getTenantId() === $tenant->value));
    }

    /**
     * See the implemented interface.
     */
    public function schedules(TenantRef $tenant): array
    {
        $rows = $this->em()->getRepository(ScheduleAssignment::class)->findBy(['tenantId' => $tenant->value], ['validFrom' => 'DESC', 'createdAt' => 'DESC']);

        return array_values(array_filter($rows, static fn (ScheduleAssignment $row): bool => $row->getTenantId() === $tenant->value));
    }

    /**
     * See the implemented interface.
     */
    public function schedule(string $id): ?ScheduleAssignment
    {
        return $this->em()->find(ScheduleAssignment::class, $id);
    }

    /**
     * See the implemented interface.
     */
    public function addSchedule(ScheduleAssignment $schedule): void
    {
        $this->em()->persist($schedule);
    }

    /**
     * See the implemented interface.
     */
    public function removeSchedule(ScheduleAssignment $schedule): void
    {
        $this->em()->remove($schedule);
    }

    /**
     * See the implemented interface.
     */
    public function export(string $id): ?RegisterExport
    {
        return $this->em()->find(RegisterExport::class, $id);
    }

    /**
     * See the implemented interface.
     */
    public function addExport(RegisterExport $export): void
    {
        $this->em()->persist($export);
    }

    /**
     * See the implemented interface.
     */
    public function exports(TenantRef $tenant, ?string $requestedBy, int $limit): array
    {
        $criteria = ['tenantId' => $tenant->value];
        if ($requestedBy !== null) {
            $criteria['requestedById'] = $requestedBy;
        }
        $rows = $this->em()->getRepository(RegisterExport::class)->findBy($criteria, ['createdAt' => 'DESC'], $limit);

        return array_values(array_filter($rows, static fn (RegisterExport $export): bool => $export->getTenantId() === $tenant->value && ($requestedBy === null || $export->getRequestedById() === $requestedBy)));
    }

    /**
     * See the implemented interface.
     */
    public function exportsAbout(string $ref): array
    {
        /** @var list<RegisterExport> $rows */
        $rows = $this->em()->createQueryBuilder()->select('e')->from(RegisterExport::class, 'e')
            ->where('e.requestedById = :ref OR e.workerId = :ref')->setParameter('ref', $ref)
            ->orderBy('e.createdAt')->getQuery()->getResult();

        return $rows;
    }

    /**
     * See the implemented interface.
     */
    public function claimExport(string $id): bool
    {
        $claimed = $this->em()->createQueryBuilder()->update(RegisterExport::class, 'e')
            ->set('e.status', ':running')->where('e.id = :id AND e.status = :pending')
            ->setParameter('running', ExportStatus::Running->value)->setParameter('pending', ExportStatus::Pending->value)->setParameter('id', $id)
            ->getQuery()->execute();

        return $claimed === 1;
    }

    /**
     * See the implemented interface.
     */
    public function expiredExports(DateTimeImmutable $now, int $limit): array
    {
        /** @var list<RegisterExport> $rows */
        $rows = $this->em()->createQueryBuilder()->select('e')->from(RegisterExport::class, 'e')
            ->where('e.status = :ready AND e.expiresAt <= :now')
            ->setParameter('ready', ExportStatus::Ready->value)->setParameter('now', $now, UtcDateTimeImmutableType::NAME)
            ->orderBy('e.expiresAt')->setMaxResults($limit)->getQuery()->getResult();

        return $rows;
    }

    /**
     * See the implemented interface.
     */
    public function addNotice(RegisterNotice $notice): bool
    {
        if ($this->hasNotice($notice->getDedupeKey())) {
            return false;
        }
        $this->em()->persist($notice);

        return true;
    }

    /**
     * See the implemented interface.
     */
    public function hasNotice(string $dedupeKey): bool
    {
        return $this->em()->getRepository(RegisterNotice::class)->count(['dedupeKey' => $dedupeKey]) > 0;
    }

    /**
     * See the implemented interface.
     */
    public function dueNotices(DateTimeImmutable $now, int $limit): array
    {
        /** @var list<RegisterNotice> $rows */
        $rows = $this->em()->createQueryBuilder()->select('n')->from(RegisterNotice::class, 'n')
            ->where('n.sentAt IS NULL AND n.deliverableFrom <= :now')->setParameter('now', $now, UtcDateTimeImmutableType::NAME)
            ->orderBy('n.deliverableFrom')->addOrderBy('n.createdAt')->setMaxResults($limit)->getQuery()->getResult();

        return $rows;
    }

    /**
     * See the implemented interface.
     */
    public function markNoticeSent(string $id, DateTimeImmutable $at): bool
    {
        $marked = $this->em()->createQueryBuilder()->update(RegisterNotice::class, 'n')
            ->set('n.sentAt', ':at')->where('n.id = :id AND n.sentAt IS NULL')
            ->setParameter('at', WorkdayRevision::utc($at), UtcDateTimeImmutableType::NAME)->setParameter('id', $id)
            ->getQuery()->execute();

        return $marked === 1;
    }

    /**
     * @param list<Workday> $rows
     *
     * @return list<Workday>
     */
    private function own(TenantRef $tenant, WorkerRef $worker, array $rows): array
    {
        return array_values(array_filter($rows, static fn (Workday $workday): bool => $workday->getTenantId() === $tenant->value && $workday->getWorkerId() === $worker->value));
    }

    private function em(): EntityManagerInterface
    {
        return $this->runner->manager();
    }
}
