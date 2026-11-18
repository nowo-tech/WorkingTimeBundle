<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function array_filter;
use function array_values;

/**
 * The leave foundations' persistence on the bundle's entity manager. Every lookup is scoped by the tenant (and the
 * worker) and the result re-checked with `===` (rule LQ). Allowance lines have no update or delete path (append-only
 * in code; only the expiry mark changes).
 */
final readonly class DoctrineLeaveStore implements LeaveStoreInterface
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
    public function settings(TenantRef $tenant): ?LeaveSettings
    {
        $settings = $this->em()->find(LeaveSettings::class, $tenant->value);

        return $settings instanceof LeaveSettings && $settings->getTenantId() === $tenant->value ? $settings : null;
    }

    /**
     * See the implemented interface.
     */
    public function addSettings(LeaveSettings $settings): void
    {
        $this->em()->persist($settings);
    }

    /**
     * See the implemented interface.
     */
    public function types(TenantRef $tenant): array
    {
        /** @var list<LeaveType> $rows */
        $rows = $this->em()->createQueryBuilder()->select('t')->from(LeaveType::class, 't')
            ->where('t.tenantId = :tenant')->setParameter('tenant', $tenant->value)
            ->orderBy('t.sortOrder')->addOrderBy('t.createdAt')->addOrderBy('t.id')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (LeaveType $t): bool => $t->getTenantId() === $tenant->value));
    }

    /**
     * See the implemented interface.
     */
    public function type(TenantRef $tenant, string $id, bool $lock = false): ?LeaveType
    {
        $type = $this->em()->find(LeaveType::class, $id, $lock ? LockMode::PESSIMISTIC_WRITE : null);

        return $type instanceof LeaveType && $type->getTenantId() === $tenant->value && $type->getId() === $id ? $type : null;
    }

    /**
     * See the implemented interface.
     */
    public function typeBySeed(TenantRef $tenant, string $seedCode): ?LeaveType
    {
        // Byte-exact over the tenant's own types (audit TD-M1): never a collation-dependent findOneBy.
        foreach ($this->types($tenant) as $type) {
            if ($type->getSeedCode() === $seedCode) {
                return $type;
            }
        }

        return null;
    }

    /**
     * See the implemented interface.
     */
    public function typeInUse(TenantRef $tenant, string $typeId): bool
    {
        foreach ([AllowancePolicy::class, AllowanceLine::class] as $class) {
            $found = $this->em()->createQueryBuilder()->select('r.id')->from($class, 'r')
                ->where('r.tenantId = :tenant AND r.leaveTypeId = :type')
                ->setParameter('tenant', $tenant->value)->setParameter('type', $typeId)
                ->setMaxResults(1)->getQuery()->getScalarResult();
            if ($found !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * See the implemented interface.
     */
    public function addType(LeaveType $type): void
    {
        $this->em()->persist($type);
    }

    /**
     * See the implemented interface.
     */
    public function policies(TenantRef $tenant, int $year): array
    {
        /** @var list<AllowancePolicy> $rows */
        $rows = $this->em()->createQueryBuilder()->select('p')->from(AllowancePolicy::class, 'p')
            ->where('p.tenantId = :tenant AND p.leaveYear = :year')->setParameter('tenant', $tenant->value)->setParameter('year', $year)
            ->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (AllowancePolicy $p): bool => $p->getTenantId() === $tenant->value));
    }

    /**
     * See the implemented interface.
     */
    public function policy(TenantRef $tenant, string $id): ?AllowancePolicy
    {
        $policy = $this->em()->find(AllowancePolicy::class, $id);

        return $policy instanceof AllowancePolicy && $policy->getTenantId() === $tenant->value && $policy->getId() === $id ? $policy : null;
    }

    /**
     * See the implemented interface.
     */
    public function addPolicy(AllowancePolicy $policy): void
    {
        $this->em()->persist($policy);
    }

    /**
     * See the implemented interface.
     */
    public function removePolicy(AllowancePolicy $policy): void
    {
        $this->em()->remove($policy);
    }

    /**
     * See the implemented interface.
     */
    public function lines(TenantRef $tenant, WorkerRef $worker, int $year): array
    {
        /** @var list<AllowanceLine> $rows */
        $rows = $this->em()->createQueryBuilder()->select('l')->from(AllowanceLine::class, 'l')
            ->where('l.tenantId = :tenant AND l.workerId = :worker AND l.leaveYear = :year')
            ->setParameter('tenant', $tenant->value)->setParameter('worker', $worker->value)->setParameter('year', $year)
            ->orderBy('l.createdAt')->addOrderBy('l.id')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (AllowanceLine $l): bool => $l->getTenantId() === $tenant->value && $l->getWorkerId() === $worker->value));
    }

    /**
     * See the implemented interface.
     */
    public function addLine(AllowanceLine $line): void
    {
        $this->em()->persist($line);
    }

    /**
     * See the implemented interface.
     */
    public function linesOfWorker(WorkerRef $worker): array
    {
        /** @var list<AllowanceLine> $rows */
        $rows = $this->em()->createQueryBuilder()->select('l')->from(AllowanceLine::class, 'l')
            ->where('l.workerId = :worker')->setParameter('worker', $worker->value)
            ->orderBy('l.createdAt')->addOrderBy('l.id')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (AllowanceLine $l): bool => $l->getWorkerId() === $worker->value));
    }

    /**
     * See the implemented interface.
     */
    public function policiesOfWorker(WorkerRef $worker): array
    {
        /** @var list<AllowancePolicy> $rows */
        $rows = $this->em()->createQueryBuilder()->select('p')->from(AllowancePolicy::class, 'p')
            ->where('p.scope = :scope AND p.targetId = :worker')->setParameter('scope', ScheduleScope::Worker)->setParameter('worker', $worker->value)
            ->orderBy('p.createdAt')->addOrderBy('p.id')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (AllowancePolicy $p): bool => $p->getTargetId() === $worker->value));
    }

    /**
     * See the implemented interface.
     */
    public function erasePersonalData(WorkerRef $worker): array
    {
        $connection = $this->em()->getConnection();
        $lines      = $this->em()->getClassMetadata(AllowanceLine::class)->getTableName();
        $policies   = $this->em()->getClassMetadata(AllowancePolicy::class)->getTableName();
        $reasons    = (int) $connection->executeStatement('UPDATE ' . $lines . ' SET reason = ? WHERE worker_id = ? AND reason <> ?', [AllowanceLine::ERASED, $worker->value, AllowanceLine::ERASED]);
        $notes      = (int) $connection->executeStatement('UPDATE ' . $policies . ' SET note = NULL WHERE scope = ? AND target_id = ? AND note IS NOT NULL', [ScheduleScope::Worker->value, $worker->value]);
        $actors     = (int) $connection->executeStatement('UPDATE ' . $lines . ' SET created_by_id = NULL WHERE created_by_id = ?', [$worker->value])
            + (int) $connection->executeStatement('UPDATE ' . $policies . ' SET created_by_id = NULL WHERE created_by_id = ?', [$worker->value]);

        return ['reasons' => $reasons, 'notes' => $notes, 'actor_refs' => $actors];
    }

    /**
     * See the implemented interface.
     */
    public function expiringLines(LocalDate $before, int $limit): array
    {
        /** @var list<AllowanceLine> $rows */
        $rows = $this->em()->createQueryBuilder()->select('l')->from(AllowanceLine::class, 'l')
            ->where('l.expiredAt IS NULL AND l.expiresOn IS NOT NULL AND l.expiresOn < :before')
            ->setParameter('before', $before->toDateTime(), 'date_immutable')
            ->orderBy('l.id')->setMaxResults($limit)->getQuery()->getResult();

        return $rows;
    }

    private function em(): EntityManagerInterface
    {
        return $this->runner->manager();
    }
}
