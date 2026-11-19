<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Throwable;

use function array_fill_keys;
use function array_pop;
use function array_reverse;
use function array_values;
use function spl_object_id;

/**
 * What one attempt of a bundle write unit did through the host's entity managers (audits UZ-1, UZ2, UZ3), on the
 * private manager only. Created per attempt and dropped with it: it is never a service and nothing outlives the
 * attempt (FrankenPHP worker mode, kernel reset off).
 *
 * - open(): records every open host manager (every manager of the registry except the bundle's) and listens to
 *   `onFlush` / `postFlush` on their event managers, filtered to those managers.
 * - A host flush that writes is recorded at `onFlush` (change sets computed, its own transaction not begun yet) with
 *   its inserts, its updates and their old values, its deletes with their identifiers and original data, and whether
 *   it runs on the bundle's connection. Flushes nest (a host `postFlush` listener may flush again): one stack per
 *   manager. Reads and empty flushes are never recorded.
 * - At `postFlush` a flush on the bundle's connection ran inside the unit's transaction, so the unit's rollback undoes
 *   it: its writes are kept for refusal(). A flush on any other connection is out of the unit's hands (committed, or
 *   inside the host's own transaction, which the host commits): it counts as committed (CONFLICT_002).
 * - close(): removes the listeners (always, in the caller's `finally`).
 * - refusal(): after a transient failure, the ConcurrencyConflict that ends the attempt without a replay, or null to
 *   let transaction-doctrine replay. See the method.
 */
final class HostWriteProbe
{
    /** @var array<int, EntityManagerInterface> The host managers open when the attempt started, by object id */
    private array $hosts = [];

    /**
     * @var array<int, list<array{bool, list<object>, list<array{object, array<string, mixed>}>, list<array{object, array<string, mixed>, array<string, mixed>}>}>>
     *                                                                                                                                                              Per host manager, the running flushes (a stack): on the bundle's connection, inserts, updates with their old
     *                                                                                                                                                              values, deletes with their identifier and original data
     */
    private array $running = [];

    /** @var array<int, list<object>> Per host manager: inserts the unit's rollback undid */
    private array $inserted = [];

    /** @var array<int, list<array{object, array<string, mixed>}>> Per host manager: updates the rollback undid, with the old values */
    private array $updated = [];

    /** @var array<int, list<array{object, array<string, mixed>, array<string, mixed>}>> Per host manager: deletes the rollback undid */
    private array $deleted = [];

    /** Whether a host flush wrote during the attempt. */
    private bool $flushed = false;

    /** Whether a host flush outside the unit's transaction finished (committed, or the host's own transaction will). */
    private bool $committed = false;

    private function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Records the open host managers and starts listening to their flushes.
     */
    public static function open(ManagerRegistry $registry, EntityManagerInterface $own): self
    {
        $probe = new self($own->getConnection());
        foreach ($registry->getManagers() as $manager) {
            if (!$manager instanceof EntityManagerInterface || $manager === $own || !$manager->isOpen()) {
                continue;
            }
            $probe->hosts[spl_object_id($manager)] = $manager;
            $manager->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], $probe);
        }

        return $probe;
    }

    /**
     * Stops listening.
     */
    public function close(): void
    {
        foreach ($this->hosts as $manager) {
            $manager->getEventManager()->removeEventListener([Events::onFlush, Events::postFlush], $this);
        }
    }

    /**
     * `onFlush` of a host manager: the change sets are computed, the flush's own transaction is not begun yet.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $id      = spl_object_id($manager);
        if (!isset($this->hosts[$id])) {
            return;
        }
        $unitOfWork = $manager->getUnitOfWork();
        $insertions = $unitOfWork->getScheduledEntityInsertions();
        $updates    = $unitOfWork->getScheduledEntityUpdates();
        $deletions  = $unitOfWork->getScheduledEntityDeletions();
        if ($insertions === [] && $updates === [] && $deletions === []
            && $unitOfWork->getScheduledCollectionUpdates() === [] && $unitOfWork->getScheduledCollectionDeletions() === []) {
            return; // an empty flush writes nothing
        }
        $old = [];
        foreach ($updates as $entity) {
            $values = [];
            foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $change) {
                $values[$field] = $change[0];
            }
            $old[] = [$entity, $values];
        }
        $gone = [];
        foreach ($deletions as $entity) {
            /** @var array<string, mixed> $identifier field => value */
            $identifier = $unitOfWork->getEntityIdentifier($entity);
            $gone[]     = [$entity, $identifier, $unitOfWork->getOriginalEntityData($entity)];
        }
        $this->flushed        = true; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        $this->running[$id][] = [$manager->getConnection() === $this->connection, array_values($insertions), $old, $gone]; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
    }

    /**
     * `postFlush` of a host manager: the innermost running flush finished.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $id = spl_object_id($args->getObjectManager());
        if (($this->running[$id] ?? []) === []) {
            return;
        }
        [$local, $insertions, $updates, $deletions] = array_pop($this->running[$id]); // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        if (!$local) {
            // Not on the bundle's connection: the unit's rollback does not undo it (audit UZ3-2).
            $this->committed = true; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt

            return;
        }
        foreach ($insertions as $entity) {
            $this->inserted[$id][] = $entity; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        }
        foreach ($updates as $update) {
            $this->updated[$id][] = $update; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        }
        foreach ($deletions as $deletion) {
            $this->deleted[$id][] = $deletion; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        }
    }

    /**
     * The ConcurrencyConflict that ends the attempt instead of a replay, or null to let transaction-doctrine replay:
     *
     * - a host flush wrote, or a host manager open before the attempt is closed now: never replayed. `committed` when
     *   a host flush outside the unit's transaction finished (CONFLICT_002); otherwise the host's writes went with the
     *   unit's rollback (CONFLICT_001). The host's open manager is put back to what the database holds again (audit
     *   UZ3-1): rolled-back inserts are detached (a generated id is reset, so the entity can be persisted again),
     *   rolled-back updates get their old values back in the entity and in the original data (no pending change, and
     *   a retry writes again), rolled-back deletes are managed again. Nothing else of the host is touched or cleared;
     * - a host manager has new unflushed work (audits UZ2-5, UZ3-3; change sets computed first, so cascaded inserts
     *   count): its scheduled inserts are detached (cancelled) and the attempt is not replayed (CONFLICT_001), so
     *   transaction-doctrine never clears an open host manager;
     * - otherwise (reads only): null.
     */
    public function refusal(Throwable $failure, TransientFailureKind $kind, int $attempt): ?ConcurrencyConflict
    {
        $refuse = $this->flushed;
        foreach ($this->hosts as $id => $manager) {
            if (!$manager->isOpen()) {
                $refuse = true;

                continue;
            }
            $this->restore($manager, $id);
            $unitOfWork = $manager->getUnitOfWork();
            // The unit never starts over unflushed host work (the runner refuses it, transaction-doctrine never replays
            // over it), so every scheduled insertion now is one this attempt made, cascaded ones included.
            $unitOfWork->computeChangeSets();
            foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
                $manager->detach($entity); // @igor-ignore - an insert the attempt scheduled and never flushed; nothing is kept on a service
                $refuse = true;
            }
            // Read from the change sets computed above: computing them again would cascade a detached insert back in.
            $refuse = $refuse || $unitOfWork->getScheduledEntityUpdates() !== [] || $unitOfWork->getScheduledEntityDeletions() !== [];
        }

        return $refuse ? new ConcurrencyConflict($attempt, $kind, null, $failure, $this->committed) : null;
    }

    /**
     * Whether an open manager holds unflushed work (change sets computed).
     */
    public static function hasScheduledChanges(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== [] || $unitOfWork->getScheduledEntityUpdates() !== [] || $unitOfWork->getScheduledEntityDeletions() !== [];
    }

    /**
     * Puts the host's open manager back to the database after the unit's rollback undid its flushes (audit UZ3-1).
     */
    private function restore(EntityManagerInterface $manager, int $id): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        foreach ($this->inserted[$id] ?? [] as $entity) {
            $class = $manager->getClassMetadata($entity::class);
            if ($unitOfWork->isInIdentityMap($entity)) {
                $manager->detach($entity); // @igor-ignore - the attempt's own rolled-back insert; nothing is kept on a service
            }
            if (!$class->isIdentifierNatural()) {
                // As before its flush: a new entity the host can persist again.
                $class->setIdentifierValues($entity, array_fill_keys($class->getIdentifierFieldNames(), null));
            }
        }
        // Latest first, so an entity updated by two flushes ends with the oldest values.
        foreach (array_reverse($this->updated[$id] ?? []) as [$entity, $values]) {
            $class = $manager->getClassMetadata($entity::class);
            foreach ($unitOfWork->isInIdentityMap($entity) ? $values : [] as $field => $value) {
                $class->setFieldValue($entity, $field, $value);
                $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), $field, $value);
            }
        }
        foreach ($this->deleted[$id] ?? [] as [$entity, $identifier, $data]) {
            $class = $manager->getClassMetadata($entity::class);
            if (!$unitOfWork->isInIdentityMap($entity) && $unitOfWork->tryGetById($identifier, $class->rootEntityName) === false) {
                $class->setIdentifierValues($entity, $identifier);
                $unitOfWork->registerManaged($entity, $identifier, $data);
            }
        }
    }
}
