<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Throwable;

use function array_values;
use function spl_object_id;

/**
 * What one attempt of a bundle write unit did through the host's entity managers (audits UZ-1, UZ2), on the private
 * manager only. Created per attempt and dropped with it: it is never a service and nothing outlives the attempt
 * (FrankenPHP worker mode, kernel reset off).
 *
 * - open(): records every open host manager (every manager of the registry except the bundle's) and listens to
 *   `onFlush` / `postFlush` on their event managers, filtered to those managers.
 * - A host flush that wrote something is recorded with the entities it inserted and whether its connection was
 *   outside a transaction when it began (a flush that then finished really committed). Reads and empty flushes are
 *   never recorded.
 * - close(): removes the listeners (always, in the caller's `finally`).
 * - refusal(): after a transient failure, the ConcurrencyConflict that ends the attempt without a replay, or null to
 *   let transaction-doctrine replay. See the conditions on the method.
 */
final class HostWriteProbe
{
    /** @var array<int, EntityManagerInterface> The host managers open when the attempt started, by object id */
    private array $hosts = [];

    /** @var array<int, array{bool, list<object>}> Per host manager, the running flush: begun outside a transaction, and its insertions */
    private array $running = [];

    /** @var array<int, list<object>> Per host manager: entities inserted by its finished flushes that did not commit */
    private array $inserted = [];

    /** Whether a host flush wrote during the attempt. */
    private bool $flushed = false;

    /** Whether a host flush that began outside a transaction finished (it committed). */
    private bool $committed = false;

    private function __construct()
    {
    }

    /**
     * Snapshots the open host managers and starts listening to their flushes.
     */
    public static function open(ManagerRegistry $registry, EntityManagerInterface $own): self
    {
        $probe = new self();
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
        if ($insertions === [] && $unitOfWork->getScheduledEntityUpdates() === [] && $unitOfWork->getScheduledEntityDeletions() === []
            && $unitOfWork->getScheduledCollectionUpdates() === [] && $unitOfWork->getScheduledCollectionDeletions() === []) {
            return; // an empty flush writes nothing
        }
        $this->flushed      = true; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        $this->running[$id] = [!$manager->getConnection()->isTransactionActive(), array_values($insertions)]; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
    }

    /**
     * `postFlush` of a host manager: the flush finished; begun outside a transaction, it committed.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $id = spl_object_id($args->getObjectManager());
        if (!isset($this->running[$id])) {
            return;
        }
        [$outside, $insertions] = $this->running[$id];
        unset($this->running[$id]); // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        if ($outside) {
            // Its rows are real: they stay managed.
            $this->committed = true; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt

            return;
        }
        foreach ($insertions as $entity) {
            $this->inserted[$id][] = $entity; // @igor-ignore - per-attempt probe, never a service; dropped with the attempt
        }
    }

    /**
     * The ConcurrencyConflict that ends the attempt instead of a replay, or null to let transaction-doctrine replay:
     *
     * - a host flush wrote, or a host manager open before the attempt is closed now: never replayed. `committed` only
     *   when a host flush began outside a transaction and finished (CONFLICT_002); otherwise the host's writes went
     *   with the unit's rollback (CONFLICT_001). The entities inserted by the flushes that ran inside the unit's
     *   transaction (rolled back) are detached from their open manager; entities the host had before, only read, or
     *   inserted by a committed flush are left alone;
     * - a host manager has new scheduled work it did not flush (audit UZ2-5): its new scheduled insertions are
     *   detached (cancelled) and the attempt is not replayed (CONFLICT_001), so transaction-doctrine never clears an
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
            $unitOfWork = $manager->getUnitOfWork();
            foreach ($this->inserted[$id] ?? [] as $entity) {
                if ($unitOfWork->isInIdentityMap($entity)) {
                    $manager->detach($entity); // @igor-ignore - the attempt's own rolled-back insert; nothing is kept on a service
                }
            }
            // The unit never starts over unflushed host work (the runner refuses it, transaction-doctrine never replays
            // over it), so every scheduled insertion now is one this attempt made.
            foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
                $manager->detach($entity); // @igor-ignore - an insert the attempt scheduled and never flushed; nothing is kept on a service
                $refuse = true;
            }
            $refuse = $refuse || self::hasScheduledChanges($manager);
        }

        return $refuse ? new ConcurrencyConflict($attempt, $kind, null, $failure, $this->committed) : null;
    }

    /**
     * Whether an open manager holds unflushed updates or deletions (change sets computed).
     */
    public static function hasScheduledChanges(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== [] || $unitOfWork->getScheduledEntityUpdates() !== [] || $unitOfWork->getScheduledEntityDeletions() !== [];
    }
}
