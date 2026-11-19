<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Throwable;

use function count;
use function spl_object_id;
use function str_starts_with;

/**
 * The bundle's units of work on its entity manager (contract sections 15 and 16).
 *
 * - `write()`: a transaction-doctrine write unit (replay after a deadlock / lock-wait timeout, `ConcurrencyConflict`
 *   ⇒ 409 + Retry-After after the last attempt) around one explicit transaction that flushes before the commit; a
 *   unique key refusal becomes {@see DuplicateRowException}, an optimistic lock failure {@see StaleRowException}.
 *   A write inside a running transaction (its own, or a host's on a shared connection) runs and flushes into it.
 * - `read()`: runs and clears.
 * - Both clear the entity manager when the outermost unit ends, and a closed entity manager is reset through the
 *   registry before the next unit (worker mode: no managed entity and no broken manager survive a call).
 * - On the private entity manager the write unit is scoped to it (transaction-doctrine >= 1.7 `forManagers()`): a
 *   replay clears or resets only the bundle's manager, never the host's open default one. Every write of a unit goes
 *   through that manager (the stores take it from manager()). Host ports called inside a unit (the audit sink, the
 *   worker directory) must not write through the host's manager (audit UZ-1). If one does and the attempt meets a
 *   transient failure after the host's identity map grew (a persist, with or without a flush), the unit is not
 *   replayed: what the attempt added to the host's manager is detached (nothing else of it) and the failure ends as a
 *   ConcurrencyConflict (409; CONFLICT_002 when the host's manager is on another connection, where its flush
 *   committed). A host update of an entity it already managed does not grow the map and is replayed like before.
 *
 * Never flushes the host's entity manager (rule 6): with `entity_manager: null` it is the default one, used only in
 * explicit transactions; there the runner forgets only the bundle's entities and refuses to write while the host has
 * pending changes of its own (audit ST-M4). A configured name that resolves to the registry's default manager (for
 * example `default`) is the same shared manager and gets the same safeguards.
 */
final readonly class DoctrineTransactionRunner implements TransactionRunnerInterface
{
    /** Namespace of the bundle's entities. */
    private const ENTITY_NAMESPACE = 'Nowo\\WorkingTimeBundle\\Domain\\';

    /**
     * @param string|null $managerName the bundle's entity manager (null: the default one)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private WriteUnitInterface $writeUnit,
        private ?string $managerName = null,
    ) {
    }

    /**
     * Runs a write unit.
     */
    public function write(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            // Inside a running unit (ours, or a host transaction on a shared connection: a UserKit erasure): one
            // transaction, one replay layer; our changes are flushed into it.
            $this->refuseForeignChanges($manager);
            $result = $work();
            $manager->flush();

            return $result;
        }
        $this->refuseForeignChanges($manager);

        return $this->unit($manager)->run(function (int $attempt = 1) use ($work): mixed { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $manager = $this->manager();
            $this->release($manager);
            $host = $this->hostSnapshot($manager);
            try {
                return $manager->wrapInTransaction(static function (EntityManagerInterface $manager) use ($work): mixed { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $result = $work();
                    $manager->flush();

                    return $result;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw new DuplicateRowException($e);
            } catch (OptimisticLockException $e) {
                throw new StaleRowException($e);
            } catch (Throwable $e) {
                $this->refuseReplayAfterHostWrites($manager, $host, $e, $attempt);

                throw $e;
            } finally {
                $this->release($this->manager());
            }
        });
    }

    /**
     * The host's open default manager and the entities its identity map holds before an attempt, on the private
     * manager only (null on a shared one, which the unit writes through, or when the host's manager is closed).
     *
     * @return array{EntityManagerInterface, array<int, true>}|null
     */
    private function hostSnapshot(EntityManagerInterface $manager): ?array
    {
        if ($this->shared($manager)) {
            return null;
        }
        $host = $this->registry->getManager();
        if (!$host instanceof EntityManagerInterface || !$host->isOpen()) {
            return null;
        }

        return [$host, $this->identities($host)];
    }

    /**
     * Audit UZ-1: an attempt that met a transient failure after the host's identity map grew (a host port persisted,
     * maybe flushed, through the host's manager inside the unit) is not replayed. The entities the attempt added to
     * the host's manager are detached (a rolled-back insert must not stay managed in a worker), and the failure ends as
     * a ConcurrencyConflict, which a write unit never replays: 409, CONFLICT_002 when the host's manager is on another
     * connection (its flush committed there).
     *
     * @param array{EntityManagerInterface, array<int, true>}|null $host
     *
     * @throws ConcurrencyConflict
     */
    private function refuseReplayAfterHostWrites(EntityManagerInterface $manager, ?array $host, Throwable $failure, int $attempt): void
    {
        if ($host === null) {
            return;
        }
        $kind                   = (new TransientFailureClassifier())->classify($failure);
        [$hostManager, $before] = $host;
        if (!$kind instanceof TransientFailureKind || !$hostManager->isOpen() || $hostManager->getUnitOfWork()->size() <= count($before)) {
            return;
        }
        foreach ($hostManager->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (!isset($before[spl_object_id($entity)])) {
                    $hostManager->detach($entity); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
            }
        }

        throw new ConcurrencyConflict($attempt, $kind, null, $failure, $hostManager->getConnection() !== $manager->getConnection());
    }

    /**
     * The object ids of the entities a manager's identity map holds.
     *
     * @return array<int, true>
     */
    private function identities(EntityManagerInterface $manager): array
    {
        $ids = [];
        foreach ($manager->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                $ids[spl_object_id($entity)] = true;
            }
        }

        return $ids;
    }

    /**
     * The write unit of $manager: scoped to the private entity manager (only it is cleared or reset between two
     * attempts); unscoped on a shared manager, which is the one the unit writes through, and when the write unit is
     * not transaction-doctrine's (a host decoration). A new scoped instance per write: nothing is kept.
     */
    private function unit(EntityManagerInterface $manager): WriteUnitInterface
    {
        if ($this->managerName === null || $this->shared($manager) || !$this->writeUnit instanceof WriteUnit) {
            return $this->writeUnit;
        }

        return $this->writeUnit->forManagers($this->managerName);
    }

    /**
     * Runs a read.
     */
    public function read(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            return $work();
        }
        try {
            return $work();
        } finally {
            $this->release($manager);
        }
    }

    /**
     * Forgets the bundle's managed entities: the whole unit of work on the private entity manager; on a shared one
     * (`entity_manager: null`, or the default manager's name) only the bundle's own entities, so the host's managed
     * entities and pending changes stay (audit ST-M4).
     */
    public function release(EntityManagerInterface $manager): void
    {
        if (!$this->shared($manager)) {
            $manager->clear(); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

            return;
        }
        foreach ($manager->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            if (!str_starts_with($class, self::ENTITY_NAMESPACE)) {
                continue;
            }
            foreach ($entities as $entity) {
                $manager->detach($entity); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
    }

    /**
     * On a shared entity manager the bundle never flushes the host's pending changes (rule 6, audit ST-M4): a write
     * while the host has unflushed inserts, updates or deletes of its own entities is refused.
     *
     * @throws LogicException
     */
    private function refuseForeignChanges(EntityManagerInterface $manager): void
    {
        if (!$this->shared($manager)) {
            return;
        }
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $pending) {
            foreach ($pending as $entity) {
                if (!str_starts_with($entity::class, self::ENTITY_NAMESPACE)) {
                    throw new LogicException('nowo_working_time shares the default entity manager (doctrine.entity_manager: null or the default manager\'s name) and will not flush the host\'s pending changes: flush them before calling the register, or give the bundle its private entity manager.');
                }
            }
        }
    }

    /**
     * Whether the bundle's entity manager is the host's default one: `entity_manager: null`, or a configured name that
     * resolves to the registry's default manager.
     */
    private function shared(EntityManagerInterface $manager): bool
    {
        return $this->managerName === null || $manager === $this->registry->getManager();
    }

    /**
     * Flushes inside the running write.
     */
    public function flush(): void
    {
        $this->manager()->flush();
    }

    /**
     * The bundle's entity manager, reset first when a failure closed it.
     */
    public function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('nowo_working_time needs an ORM entity manager.');
        }

        return $manager;
    }
}
