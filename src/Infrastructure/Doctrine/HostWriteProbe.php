<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use ReflectionProperty;
use Throwable;

use function array_combine;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_reverse;
use function array_values;
use function count;
use function is_array;
use function is_object;
use function property_exists;
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
     * Per host manager, the running flushes (a stack): on the bundle's connection, inserts, updates with their old
     * values, deletes with their identifier and original data, entities to refresh.
     *
     * @var array<int, list<array{bool, list<object>, list<array{object, array<string, mixed>}>, list<array{object, array<string, mixed>, array<string, mixed>}>, list<object>}>>
     */
    private array $running = [];

    /** @var array<int, list<object>> Per host manager: inserts the unit's rollback undid */
    private array $inserted = [];

    /** @var array<int, list<array{object, array<string, mixed>}>> Per host manager: updates the rollback undid, with the old values */
    private array $updated = [];

    /** @var array<int, list<array{object, array<string, mixed>, array<string, mixed>}>> Per host manager: deletes the rollback undid */
    private array $deleted = [];

    /** @var array<int, list<object>> Per host manager: collection owners and versioned updates to refresh after the rollback */
    private array $refresh = [];

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
        // Audits UZ4-1 to UZ4-3: owners of changed collections (a to-many add or removal, an inverse collection that
        // cascaded an insert) and versioned updates are refreshed after the rollback: Doctrine keeps no old value for
        // a collection snapshot or a version.
        $refresh = [];
        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null) {
                $refresh[] = $owner;
            }
        }
        foreach ($updates as $entity) {
            if ($manager->getClassMetadata($entity::class)->isVersioned) {
                $refresh[] = $entity;
            }
        }
        $gone = [];
        foreach ($deletions as $entity) {
            // Audit UZ7-2: a clear() of an inverse orphan-removal collection is never a scheduled collection update;
            // its deleted children lead to the owner through their owning to-one association.
            $class = $manager->getClassMetadata($entity::class);
            foreach ($class->getAssociationMappings() as $field => $association) {
                $target = $association->isToOne() && $association->isOwningSide() ? $class->getFieldValue($entity, $field) : null;
                if (is_object($target) && $unitOfWork->isInIdentityMap($target)) {
                    $refresh[] = $target;
                }
            }
            /** @var array<string, mixed> $identifier field => value */
            $identifier = $unitOfWork->getEntityIdentifier($entity);
            $gone[]     = [$entity, $identifier, $unitOfWork->getOriginalEntityData($entity)];
        }
        $this->flushed        = true; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        $this->running[$id][] = [$manager->getConnection() === $this->connection, array_values($insertions), $old, $gone, $refresh]; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
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
        [$local, $insertions, $updates, $deletions, $refresh] = array_pop($this->running[$id]); // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
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
        foreach ($refresh as $owner) {
            $this->refresh[$id][] = $owner; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
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
     *   a retry writes again), rolled-back deletes are managed again, and the owners of changed collections and the
     *   versioned updates are reloaded through their persister (audits UZ4, UZ5). Nothing else is touched or cleared;
     * - a host manager has new unflushed work (audits UZ2-5, UZ3-3, UZ5-2, UZ5-3): it is reverted (see
     *   revertUnflushed()) and the attempt is not replayed (CONFLICT_001), so transaction-doctrine never clears an
     *   open host manager;
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
            $refuse = $this->revertUnflushed($manager) || $refuse;
        }

        return $refuse ? new ConcurrencyConflict($attempt, $kind, null, $failure, $this->committed) : null;
    }

    /**
     * Whether an open manager holds unflushed work, in entities or only in collections (change sets computed).
     */
    public static function hasScheduledChanges(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== [] || $unitOfWork->getScheduledEntityUpdates() !== [] || $unitOfWork->getScheduledEntityDeletions() !== []
            // Work pending only in collections, pending orphan removals included (audit UZ6-2).
            || $unitOfWork->getScheduledCollectionUpdates() !== [] || $unitOfWork->getScheduledCollectionDeletions() !== []
            // An inverse collection cleared or edited without being marked dirty (audit UZ7-3).
            || self::hasChangedCollection($manager);
    }

    /**
     * Whether a managed entity of $manager holds a to-many collection that differs from its snapshot (audit UZ7).
     */
    private static function hasChangedCollection(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                if (!$unitOfWork->isUninitializedObject($entity) && self::entityHasChangedCollection($manager, $entity)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether one of the entity's to-many collections changed.
     */
    private static function entityHasChangedCollection(EntityManagerInterface $manager, object $entity): bool
    {
        $class = $manager->getClassMetadata($entity::class);
        foreach ($class->getAssociationMappings() as $field => $association) {
            $value = $association->isToMany() ? $class->getFieldValue($entity, $field) : null;
            if ($value instanceof PersistentCollection && self::collectionChanged($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a collection changed: dirty, or (an inverse collection after clear(), audit UZ7) initialised with a
     * non-empty insert or delete diff against its snapshot.
     *
     * @param PersistentCollection<array-key, object> $collection
     */
    private static function collectionChanged(PersistentCollection $collection): bool
    {
        return $collection->isDirty() || ($collection->isInitialized() && ($collection->getDeleteDiff() !== [] || $collection->getInsertDiff() !== []));
    }

    /**
     * Puts the host's open manager back to the database after the unit's rollback undid its flushes (audits UZ3-1,
     * UZ4). Each entity is restored on its own: one that cannot be (a readonly id, a row gone) never stops the others.
     */
    private function restore(EntityManagerInterface $manager, int $id): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $inserted   = $this->inserted[$id] ?? [];
        $this->detach($manager, $inserted);
        foreach ($inserted as $entity) {
            $class = $manager->getClassMetadata($entity::class);
            if (!$class->isIdentifierNatural()) {
                // As before its flush: a new entity the host can persist again. A readonly id cannot be reset (UZ4-4).
                $this->safely(static fn () => $class->setIdentifierValues($entity, array_fill_keys($class->getIdentifierFieldNames(), null)));
            }
        }
        // Latest first, so an entity updated by two flushes ends with the oldest values.
        foreach (array_reverse($this->updated[$id] ?? []) as [$entity, $values]) {
            $class = $manager->getClassMetadata($entity::class);
            foreach ($unitOfWork->isInIdentityMap($entity) ? $values : [] as $field => $value) {
                $this->safely(static function () use ($class, $unitOfWork, $entity, $field, $value): void {
                    $class->setFieldValue($entity, $field, $value);
                    $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), $field, $value);
                });
            }
        }
        foreach ($this->deleted[$id] ?? [] as [$entity, $identifier, $data]) {
            $class = $manager->getClassMetadata($entity::class);
            if (!$unitOfWork->isInIdentityMap($entity) && $unitOfWork->tryGetById($identifier, $class->rootEntityName) === false) {
                $this->safely(static function () use ($class, $unitOfWork, $entity, $identifier, $data): void {
                    $class->setIdentifierValues($entity, $identifier);
                    $unitOfWork->registerManaged($entity, $identifier, $data);
                });
            }
        }
        // Collections and versions come back from the database (UZ4-1 to UZ4-3).
        foreach ($this->refresh[$id] ?? [] as $entity) {
            if ($unitOfWork->isInIdentityMap($entity)) {
                $this->reload($manager, $entity);
            }
        }
    }

    /**
     * Detaches $entities from $manager. detach() cascades to associations marked cascade detach (audit UZ4-5): an
     * entity the host had before that a cascade detached is managed again, with its identifier and original data.
     *
     * @param list<object> $entities
     */
    private function detach(EntityManagerInterface $manager, array $entities): void
    {
        if ($entities === []) {
            return;
        }
        $unitOfWork = $manager->getUnitOfWork();
        $targets    = [];
        foreach ($entities as $entity) {
            $targets[spl_object_id($entity)] = true;
        }
        $kept = [];
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                if (!isset($targets[spl_object_id($entity)])) {
                    $kept[] = [$entity, $unitOfWork->getEntityIdentifier($entity), $unitOfWork->getOriginalEntityData($entity)];
                }
            }
        }
        foreach ($entities as $entity) {
            if ($unitOfWork->isInIdentityMap($entity) || $unitOfWork->isScheduledForInsert($entity)) {
                $this->safely(static fn () => $manager->detach($entity)); // @igor-ignore - the attempt's own insert; nothing is kept on a service
            }
        }
        foreach ($kept as [$entity, $identifier, $data]) {
            if (!$unitOfWork->isInIdentityMap($entity)) {
                $unitOfWork->registerManaged($entity, $identifier, $data);
                // Its collections may still hold the detached insert (audit UZ5, residual c).
                $this->reload($manager, $entity);
            }
        }
    }

    /**
     * Reverts what the attempt changed in a host manager without flushing (audits UZ2-5, UZ3-3, UZ5-2, UZ5-3). The unit
     * never starts over unflushed host work, so every change found now is the failed attempt's. Nothing is computed into
     * the unit of work (computeChangeSets() would leave change sets the host's next flush writes):
     *
     * - a remove() is cancelled (the entity is managed again);
     * - an entity whose fields or owning to-one associations differ from its original data, or whose to-many collection
     *   is dirty or replaced, is reloaded from the database (so is an owner whose collection gained an unflushed,
     *   cascade-persisted child, which is then reached by nothing);
     * - a persist() is cancelled (detached).
     *
     * Entities with the DEFERRED_EXPLICIT change-tracking policy are reverted too, even when the change was never
     * passed to persist().
     *
     * @return bool Whether the attempt left unflushed host work
     */
    private function revertUnflushed(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $found      = false;
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $found = true;
            $this->safely(static fn () => $manager->persist($entity)); // @igor-ignore - cancels the attempt's remove(); nothing is kept on a service
        }
        // Audit UZ8: clear() on the owning side of a collection schedules its deletion at once and leaves it clean
        // (snapshot retaken). Its owner is reverted, and the deletion is taken off the unit of work.
        $changed  = [];
        $deletion = [];
        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            $found                                = true;
            $deletion[spl_object_id($collection)] = true;
            $owner                                = $collection->getOwner();
            if ($owner !== null && $unitOfWork->isInIdentityMap($owner)) {
                $changed[spl_object_id($owner)] = $owner;
            }
        }
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                // A read-only entity is never written (audit UZ6-3): a display-only change keeps the replay.
                if (!$unitOfWork->isScheduledForInsert($entity) && !$unitOfWork->isUninitializedObject($entity) && !$unitOfWork->isReadOnly($entity)
                    && !$manager->getClassMetadata($entity::class)->isReadOnly && $this->changed($manager, $entity)) {
                    $changed[spl_object_id($entity)] = $entity;
                }
            }
        }
        // Audit UZ9-1: clear() of an owning orphan-removal collection queues every element for orphan removal and
        // leaves no delete diff to cancel them by. Under the start guard every queued orphan removal is the attempt's.
        // Fails closed (audits UZ8-2, UZ9-1): if the unit of work cannot be reached, nothing is taken off it, so the
        // scheduled deletions stay, the answer is still a 409 and the start guard keeps refusing until the host clears
        // its manager.
        $orphans = self::cancelAllOrphanRemovals($unitOfWork);
        if ($orphans !== null) {
            $found = $found || $orphans > 0;
            self::unscheduleCollectionDeletions($unitOfWork, array_keys($deletion));
        }
        foreach ($changed as $entity) {
            $found = true;
            $this->cancelOrphanRemovals($manager, $entity);
            $this->resetCollections($manager, $entity);
            $this->reload($manager, $entity);
        }
        $inserts = array_values($unitOfWork->getScheduledEntityInsertions());
        if ($inserts !== []) {
            $found = true;
            $this->detach($manager, $inserts);
        }

        return $found;
    }

    /**
     * Whether a managed entity differs from its original data: a field, an owning to-one association, or a to-many
     * collection that is dirty or no longer the managed one. Reads only.
     */
    private function changed(EntityManagerInterface $manager, object $entity): bool
    {
        $class    = $manager->getClassMetadata($entity::class);
        $original = $manager->getUnitOfWork()->getOriginalEntityData($entity);
        foreach ($class->getFieldNames() as $field) {
            if (array_key_exists($field, $original) && $class->getFieldValue($entity, $field) !== $original[$field]) {
                return true;
            }
        }
        foreach ($class->getAssociationMappings() as $field => $association) {
            $value = $class->getFieldValue($entity, $field);
            if ($association->isToMany() ? !$value instanceof PersistentCollection || self::collectionChanged($value) : $association->isOwningSide() && $value !== ($original[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Audit UZ6-1: PersistentCollection::removeElement() schedules an orphan removal at once, and a reload of the owner
     * does not take it back. The elements removed from the entity's collections (or the whole old collection, when it
     * was replaced) are taken off the orphan-removal list before the reload.
     */
    private function cancelOrphanRemovals(EntityManagerInterface $manager, object $entity): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $class      = $manager->getClassMetadata($entity::class);
        $original   = $unitOfWork->getOriginalEntityData($entity);
        foreach ($class->getAssociationMappings() as $field => $association) {
            if (!$association->isToMany()) {
                continue;
            }
            $value = $class->getFieldValue($entity, $field);
            $old   = $original[$field] ?? null;
            /** @var array<object> $removed entities of a to-many association */
            $removed = $value instanceof PersistentCollection ? $value->getDeleteDiff() : ($old instanceof PersistentCollection ? $old->getSnapshot() : []);
            foreach ($removed as $element) {
                $unitOfWork->cancelOrphanRemoval($element);
            }
        }
    }

    /**
     * Audit UZ8-1: the entity's initialised collections go back to lazy (clean, emptied, not initialised), so whoever
     * still holds one reads the database again on its next access; never left initialised and empty.
     */
    private function resetCollections(EntityManagerInterface $manager, object $entity): void
    {
        $class = $manager->getClassMetadata($entity::class);
        foreach ($class->getAssociationMappings() as $field => $association) {
            $value = $association->isToMany() ? $class->getFieldValue($entity, $field) : null;
            if ($value instanceof PersistentCollection && $value->isInitialized()) {
                $value->setDirty(false);
                $value->unwrap()->clear();
                $value->setInitialized(false);
            }
        }
    }

    /**
     * Takes collections off a unit of work's scheduled collection deletions (audit UZ8-2). Doctrine has no public API
     * for it, so they are removed from its private `collectionDeletions` through ReflectionProperty. When that property
     * is missing or not an array (a Doctrine change), nothing is touched and false is returned: the caller fails closed.
     *
     * @param list<int> $collections spl_object_id() of each collection
     *
     * @internal public for its test only
     */
    public static function unscheduleCollectionDeletions(object $unitOfWork, array $collections): bool
    {
        return self::removeFromPrivateList($unitOfWork, 'collectionDeletions', $collections) !== null;
    }

    /**
     * Empties a unit of work's queued orphan removals (audit UZ9-1), through ReflectionProperty on its private
     * `orphanRemovals` (there is no public API to cancel them all). The number of removals taken off, or null when the
     * property is missing or not an array: nothing is touched and the caller fails closed.
     *
     * @internal public for its test only
     */
    public static function cancelAllOrphanRemovals(object $unitOfWork): ?int
    {
        return self::removeFromPrivateList($unitOfWork, 'orphanRemovals', null);
    }

    /**
     * Removes $ids (all entries when null) from a private array property of the unit of work. The number of entries
     * removed, or null when the property is missing or not an array (nothing touched).
     *
     * @param list<int>|null $ids
     */
    private static function removeFromPrivateList(object $unitOfWork, string $name, ?array $ids): ?int
    {
        if (!property_exists($unitOfWork, $name)) {
            return null;
        }
        $property = new ReflectionProperty($unitOfWork, $name);
        $list     = $property->getValue($unitOfWork);
        if (!is_array($list)) {
            return null;
        }
        $before = count($list);
        foreach ($ids ?? array_keys($list) as $id) {
            unset($list[$id]);
        }
        $property->setValue($unitOfWork, $list); // @igor-ignore - the unit of work's own list, emptied of the failed attempt's entries

        return $before - count($list);
    }

    /**
     * Reloads a managed entity (the callers check it) from the database through its persister (audit UZ5-1): fields, version and to-many
     * collections come back, with no cascade (EntityManager::refresh() cascades first and would stop at a detached
     * entity). Only postLoad runs.
     */
    private function reload(EntityManagerInterface $manager, object $entity): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $class      = $manager->getClassMetadata($entity::class);
        $this->safely(static fn () => $unitOfWork->getEntityPersister($class->name)->refresh(array_combine($class->getIdentifierFieldNames(), $unitOfWork->getEntityIdentifier($entity)), $entity)); // @igor-ignore - persistence call on the host's manager; nothing is kept on a service
    }

    /**
     * Runs one restore step; a failure (a readonly property, a row the database no longer has) skips that step only.
     */
    private function safely(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            // This entity stays as it is; the others are still restored and the answer stays a 409.
        }
    }
}
