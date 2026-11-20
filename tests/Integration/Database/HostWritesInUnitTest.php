<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\HostWriteProbe;
use Nowo\WorkingTimeBundle\Tests\Fixtures\AttemptCounter;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostEvent;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostKid;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostLabel;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostReadonly;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostTask;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostVersioned;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostSchema;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use Throwable;

use function is_numeric;

/**
 * Host ports writing through a host entity manager inside a bundle unit on the private manager (audits UZ-1, UZ2;
 * docs/CONCURRENCY.md, "Replays and the host's entity manager"). The unit's first attempt meets a deadlock:
 *
 * - reads (and empty flushes) through the host's manager are replayed and nothing of the host is detached;
 * - a host flush that wrote is never replayed: 409 CONFLICT_001 when it went with the unit's rollback (same
 *   connection; the inserted entities are detached), CONFLICT_002 when it committed on the host's own connection;
 * - a host manager closed during the attempt is never replayed;
 * - an unflushed host insert is cancelled (detached) and not replayed, so the host's manager is never cleared;
 * - a unit does not start while the host has unflushed work.
 *
 * Standard host: the host's manager shares the bundle's connection. DDD host: the host's manager has its own.
 */
final class HostWritesInUnitTest extends RegisterTestCase
{
    private ?Host $hostKind = null;

    protected function host(): Host
    {
        return $this->hostKind ?? Host::standard();
    }

    public function testAReadThroughTheHostManagerIsReplayed(): void
    {
        $host = $this->hostManager();
        $host->persist(new HostNote('n0', 'a'));
        $host->flush();
        $host->clear();
        $attempts = new AttemptCounter();

        $result = $this->runner()->write(static function () use ($attempts, $host): string {
            ++$attempts->count;
            $host->find(HostNote::class, 'n0'); // a worker directory reading through the host's manager
            $host->flush(); // an empty flush writes nothing

            if ($attempts->count === 1) {
                throw self::deadlock();
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts->count, 'replayed');
        self::assertSame(1, $host->getUnitOfWork()->size(), 'what was read stays managed');
        self::assertInstanceOf(HostNote::class, $host->find(HostNote::class, 'n0'));
    }

    /**
     * Audit UZ3-1: the update went with the rollback, so the host's copy gets the database's value back (entity and
     * original data). The client's retry after the 409, in the next request of the same worker (the host's manager is
     * not cleared), writes the change for real.
     */
    public function testAHostUpdateFlushedInsideTheUnitIsRolledBackInTheHostAndTheRetryWritesIt(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();

        $conflict = $this->conflict($host, static function () use ($host, $note): void {
            $note->text = 'b';
            $host->flush();
            $note->text = 'c'; // a second flush of the same entity: the oldest value comes back
            $host->flush();
        });

        self::assertFalse($conflict->committed, 'the update went with the rollback: CONFLICT_001');
        self::assertSame('a', $this->text("SELECT text FROM host_note WHERE id = 'n0'"), 'never replayed over a lost write');
        self::assertTrue($host->contains($note), 'an entity the host had before stays managed');
        self::assertSame('a', $note->text, 'the host\'s copy matches the database again');
        self::assertFalse(HostWriteProbe::hasScheduledChanges($host), 'nothing pending: the next unit may start');

        $retry = $this->runner()->write(static function () use ($host, $note): string {
            $note->text = 'b';
            $host->flush();

            return 'ok';
        });

        self::assertSame('ok', $retry);
        self::assertSame('b', $this->text("SELECT text FROM host_note WHERE id = 'n0'"), 'the retry persists b');
    }

    /**
     * Audit UZ3-1: a rolled-back delete is managed again (with its generated id back), and the retry deletes it.
     */
    public function testAHostDeleteFlushedInsideTheUnitIsManagedAgainAndTheRetryDeletesIt(): void
    {
        $host  = $this->hostManager();
        $note  = new HostNote('n0', 'a');
        $event = new HostEvent('generated id');
        $host->persist($note);
        $host->persist($event);
        $host->flush();
        $eventId = $event->id;

        $conflict = $this->conflict($host, static function () use ($host, $note, $event): void {
            $host->remove($note);
            $host->remove($event);
            $host->flush();
        });

        self::assertFalse($conflict->committed);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'n0'"), 'the delete was rolled back');
        self::assertTrue($host->contains($note), 'managed again');
        self::assertTrue($host->contains($event), 'managed again');
        self::assertSame($eventId, $event->id, 'its generated id is back');

        $this->runner()->write(static function () use ($host, $note, $event): void {
            $host->remove($note);
            $host->remove($event);
            $host->flush();
        });

        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_note'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_event'));
    }

    /**
     * A rolled-back insert with a generated id is detached and its id reset: the host can persist it again.
     */
    public function testARolledBackInsertWithAGeneratedIdCanBePersistedAgain(): void
    {
        $host  = $this->hostManager();
        $event = new HostEvent('generated id');

        $this->conflict($host, static function () use ($host, $event): void {
            $host->persist($event);
            $host->flush();
        });

        self::assertFalse($host->contains($event));
        self::assertNull($event->id, 'as before its flush');
        $host->persist($event);
        $host->flush();
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM host_event'));
    }

    /**
     * Audit UZ3-3: an insert cascaded from an entity the host manages (no persist(), no flush()) is cancelled too.
     */
    public function testACascadedUnflushedHostInsertIsCancelled(): void
    {
        $host  = $this->hostManager();
        $event = new HostEvent('managed');
        $host->persist($event);
        $host->flush();
        $note = new HostNote('c1', 'cascaded');

        $conflict = $this->conflict($host, static function () use ($event, $note): void {
            $event->note = $note;
        });

        self::assertFalse($conflict->committed);
        self::assertFalse($host->contains($note));
        self::assertFalse($host->getUnitOfWork()->isScheduledForInsert($note), 'cancelled');
        self::assertTrue($host->contains($event));
    }

    /**
     * Audit UZ3-4: a host postFlush listener that flushes again (a nested flush) is followed too.
     */
    public function testANestedHostFlushFromAPostFlushListenerIsFollowed(): void
    {
        $host     = $this->hostManager();
        $outer    = new HostNote('n1', 'outer');
        $nested   = new HostNote('n2', 'nested');
        $listener = new class($nested) {
            private bool $done = false;

            public function __construct(private readonly HostNote $nested)
            {
            }

            public function postFlush(PostFlushEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $args->getObjectManager()->persist($this->nested);
                $args->getObjectManager()->flush();
            }
        };
        $host->getEventManager()->addEventListener([Events::postFlush], $listener);
        try {
            $conflict = $this->conflict($host, static function () use ($host, $outer): void {
                $host->persist($outer);
                $host->flush();
            });
        } finally {
            $host->getEventManager()->removeEventListener([Events::postFlush], $listener);
        }

        self::assertFalse($conflict->committed);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_note'), 'both inserts went with the rollback');
        self::assertFalse($host->contains($outer));
        self::assertFalse($host->contains($nested));
    }

    public function testAHostInsertFlushedAndClearedInsideTheUnitIsNotReplayed(): void
    {
        $host = $this->hostManager();

        $conflict = $this->conflict($host, static function () use ($host): void {
            $host->persist(new HostNote('n1', 'audit row'));
            $host->flush();
            $host->clear();
        });

        self::assertFalse($conflict->committed);
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'n1'"));
    }

    public function testAHostInsertFlushedInsideTheUnitIsDetachedAfterTheRollback(): void
    {
        $host = $this->hostManager();
        $kept = new HostNote('n0', 'before the unit');
        $host->persist($kept);
        $host->flush();
        $note = new HostNote('n1', 'audit row');

        $conflict = $this->conflict($host, static function () use ($host, $note): void {
            $host->persist($note);
            $host->flush();
        });

        self::assertFalse($conflict->committed);
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'n1'"), 'rolled back');
        self::assertFalse($host->contains($note), 'no rolled-back entity stays managed');
        self::assertTrue($host->contains($kept), 'what the host had before stays managed');
        $host->persist($note);
        $host->flush();
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'n1'"), 'the host\'s next flush stores it');
    }

    public function testAnUnflushedHostInsertIsCancelledWithoutClearingTheHost(): void
    {
        $host = $this->hostManager();
        $kept = new HostNote('n0', 'before the unit');
        $host->persist($kept);
        $host->flush();
        $event = new HostEvent('generated id');
        $note  = new HostNote('n9', 'assigned id');

        $conflict = $this->conflict($host, static function () use ($host, $event, $note): void {
            $host->persist($event);
            $host->persist($note);
        });

        self::assertFalse($conflict->committed);
        self::assertFalse($host->contains($event), 'cancelled');
        self::assertFalse($host->contains($note), 'cancelled');
        self::assertTrue($host->contains($kept), 'the host manager is not cleared');
        $host->flush();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_event'));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'n9'"));
    }

    /**
     * Audit UZ5-3: an unflushed host update made inside the failed attempt is reverted, so the host's next flush never
     * writes it; the next bundle write starts.
     */
    public function testAnUnflushedHostUpdateIsRevertedAndNeverWrittenLater(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();

        $conflict = $this->conflict($host, static function () use ($note): void {
            $note->text = 'changed, not flushed';
        });

        self::assertFalse($conflict->committed);
        self::assertTrue($host->contains($note));
        self::assertSame('a', $note->text, 'reverted');
        $host->flush();
        self::assertSame('a', $this->text("SELECT text FROM host_note WHERE id = 'n0'"), 'never written later');
        self::assertSame('ok', $this->runner()->write(static fn (): string => 'ok'));
    }

    /**
     * Audit UZ6-1: an unflushed removeElement() on an orphan-removal collection is taken off Doctrine's orphan-removal
     * list before the owner is reloaded: the host's next flush keeps both kids.
     */
    public function testAnUnflushedOrphanRemovalIsCancelled(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        foreach (['k1', 'k2'] as $id) {
            $kid       = new HostKid($id);
            $kid->note = $note;
            $note->kids->add($kid);
        }
        $host->persist($note);
        $host->flush();
        $first = $note->kids->first();
        self::assertInstanceOf(HostKid::class, $first);

        $this->conflict($host, static function () use ($note, $first): void {
            $note->kids->removeElement($first);
        });

        $host->flush();
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM host_kid'), 'the host\'s flush keeps both kids');
        self::assertCount(2, $note->kids);
    }

    /**
     * Audit UZ7-1: an unflushed clear() of an inverse orphan-removal collection (clean, but every kid on the orphan
     * list) is reverted: the host's next flush keeps both kids.
     */
    public function testAnUnflushedClearOfAnOrphanRemovalCollectionIsReverted(): void
    {
        $host = $this->hostManager();
        $note = $this->noteWithKids($host);

        $this->conflict($host, static function () use ($note): void {
            $note->kids->clear();
        });

        $host->flush();
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM host_kid'), 'the host\'s flush keeps both kids');
        self::assertCount(2, $note->kids);
    }

    /**
     * Audit UZ7-2: a flushed clear() of an inverse orphan-removal collection is rolled back; the owner is reloaded
     * (reached through the deleted kids), so the retry's clear() deletes the kids for real.
     */
    public function testAFlushedClearOfAnOrphanRemovalCollectionIsDoneByTheRetry(): void
    {
        $host = $this->hostManager();
        $note = $this->noteWithKids($host);

        $this->conflict($host, static function () use ($host, $note): void {
            $note->kids->clear();
            $host->flush();
        });

        self::assertSame(2, $this->int('SELECT COUNT(*) FROM host_kid'), 'rolled back');
        self::assertCount(2, $note->kids, 'the owner shows the database\'s kids again');

        $this->runner()->write(static function () use ($host, $note): void {
            $note->kids->clear();
            $host->flush();
        });

        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_kid'), 'the retry deletes the kids');
    }

    /**
     * Audit UZ7-3: the host's own clear() of an inverse orphan-removal collection before the unit refuses the start, so
     * it is never reverted.
     */
    public function testAHostClearBeforeTheUnitIsRefused(): void
    {
        $host = $this->hostManager();
        $note = $this->noteWithKids($host);
        $note->kids->clear();

        try {
            $this->runner()->write(static fn (): string => 'not run');
            self::fail('A write while a host collection was cleared is refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('has unflushed changes', $e->getMessage());
        }

        $host->flush();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_kid'), 'the host\'s own clear() is kept');
    }

    /**
     * Audit UZ8-1/UZ8-2: an unflushed clear() on the owning side of a many-to-many (deletion scheduled at once, the
     * collection left clean and empty) is reverted: the deletion is taken off the unit of work, the owner shows the
     * database's links, the next write starts, and the retry's clear() deletes the links for real.
     */
    public function testAnUnflushedOwningSideClearIsRevertedAndTheRetryDeletesTheLinks(): void
    {
        $host = $this->hostManager();
        $note = $this->noteWithLabels($host);
        $held = $note->labels;

        $this->conflict($host, static function () use ($note): void {
            $note->labels->clear();
        });

        self::assertSame([], $host->getUnitOfWork()->getScheduledCollectionDeletions(), 'taken off the unit of work');
        self::assertCount(2, $note->labels, 'the owner shows the database\'s links');
        self::assertCount(2, $held, 'a collection held from before reads the database again');
        self::assertFalse(HostWriteProbe::hasScheduledChanges($host));

        $this->runner()->write(static function () use ($host, $note): void {
            $note->labels->clear();
            $host->flush();
        });

        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_note_label'), 'the retry deletes the links');
    }

    /**
     * Audit UZ8: clear() and re-add of the same links without a flush: the host's flush keeps both links and the next
     * bundle write starts.
     */
    public function testAnUnflushedOwningSideClearAndReAddKeepsTheLinks(): void
    {
        $host   = $this->hostManager();
        $note   = $this->noteWithLabels($host);
        $labels = $note->labels->toArray();

        $this->conflict($host, static function () use ($note, $labels): void {
            $note->labels->clear();
            foreach ($labels as $label) {
                $note->labels->add($label);
            }
        });

        $host->flush();
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM host_note_label'), 'the host\'s flush keeps both links');
        self::assertSame('ok', $this->runner()->write(static fn (): string => 'ok'), 'the next bundle write starts');
    }

    /**
     * Audit UZ8-2: on the pinned Doctrine ORM the unit of work's private collectionDeletions has the shape the probe
     * expects (an array), so the revert does not fail closed.
     */
    public function testThePinnedOrmKeepsTheCollectionDeletionsShape(): void
    {
        $host = $this->hostManager();

        self::assertTrue(HostWriteProbe::unscheduleCollectionDeletions($host->getUnitOfWork(), []));
    }

    /**
     * Audit UZ6-2: host work pending only in a collection (a link, an orphan removal) refuses the start like any other
     * unflushed work.
     */
    public function testHostWorkPendingOnlyInACollectionIsRefused(): void
    {
        $host  = $this->hostManager();
        $note  = new HostNote('n0', 'a');
        $label = new HostLabel('l1');
        $host->persist($note);
        $host->persist($label);
        $host->flush();
        $note->labels->add($label);

        try {
            $this->runner()->write(static fn (): string => 'not run');
            self::fail('A write while a host collection has unflushed work is refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('has unflushed changes', $e->getMessage());
        }

        $host->flush();
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM host_note_label'), 'nothing of the host was lost');
    }

    /**
     * Audit UZ6-3: a change in memory to a read-only host entity (display only) is never written, so a reads-only
     * attempt keeps its replay.
     */
    public function testAReadOnlyHostEntityChangedInMemoryKeepsTheReplay(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();
        $host->getUnitOfWork()->markReadOnly($note);
        $attempts = new AttemptCounter();

        $result = $this->runner()->write(static function () use ($attempts, $note): string {
            ++$attempts->count;
            $note->text = 'shown, never saved';
            if ($attempts->count === 1) {
                throw self::deadlock();
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts->count, 'replayed');
    }

    /**
     * An unflushed remove() made inside the failed attempt is cancelled.
     */
    public function testAnUnflushedHostRemoveIsCancelled(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();

        $this->conflict($host, static function () use ($host, $note): void {
            $host->remove($note);
        });

        self::assertTrue($host->contains($note));
        $host->flush();
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'n0'"), 'never deleted later');
    }

    /**
     * Audit UZ5-1: the owner of a cascade-all inverse collection is reloaded without cascading, so the rolled-back
     * child it still held is gone and the host's next flush writes nothing.
     */
    public function testACascadeAllOwnerOfARolledBackChildIsReloaded(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();
        $task = new HostTask('child');

        $this->conflict($host, static function () use ($host, $note, $task): void {
            $task->note = $note;
            $note->tasks->add($task);
            $host->flush();
        });

        self::assertFalse($host->contains($task));
        self::assertCount(0, $note->tasks, 'the owner\'s collection is the database\'s again');
        $host->flush();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_task'), 'the host\'s next flush writes nothing');
    }

    /**
     * Audit UZ5-2: a child added to a cascade-persist inverse collection without a flush is dropped with its owner's
     * reload: no row later, and the next bundle write starts.
     */
    public function testAnUnflushedChildInAnInverseCollectionIsDropped(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();
        $child = new HostEvent('unflushed child');

        $this->conflict($host, static function () use ($note, $child): void {
            $child->note = $note;
            $note->events->add($child);
        });

        self::assertCount(0, $note->events);
        $host->flush();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_event'), 'no row later');
        self::assertSame('ok', $this->runner()->write(static fn (): string => 'ok'), 'the next bundle write starts');
    }

    /**
     * Audit UZ5 (residual c): a cascade-detach victim whose inverse collection holds the detached insert is managed
     * again and reloaded, so its collection no longer holds it and the host's next flush writes nothing.
     */
    public function testACascadeDetachVictimIsReloaded(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();
        $event = new HostEvent('linked');

        $this->conflict($host, static function () use ($host, $note, $event): void {
            $event->note = $note;
            $note->events->add($event);
            $host->persist($event);
            $host->flush();
        });

        self::assertTrue($host->contains($note), 'managed again');
        self::assertFalse($host->contains($event));
        self::assertCount(0, $note->events, 'reloaded');
        $host->flush();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_event'));
    }

    public function testAHostManagerClosedDuringTheAttemptIsNotReplayed(): void
    {
        $host = $this->hostManager();

        $conflict = $this->conflict($host, static function () use ($host): void {
            $host->close();
        });

        self::assertFalse($conflict->committed);
    }

    public function testHostWorkPendingBeforeTheUnitIsRefused(): void
    {
        $host    = $this->hostManager();
        $pending = new HostNote('p1', 'host work before the unit');
        $host->persist($pending);
        $attempts = new AttemptCounter();

        try {
            $this->runner()->write(static function () use ($attempts): void {
                ++$attempts->count;
            });
            self::fail('A write while a host manager has unflushed work is refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('has unflushed changes', $e->getMessage());
        }

        self::assertSame(0, $attempts->count, 'the unit never started');
        self::assertTrue($host->contains($pending));
        $host->flush();
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM host_note WHERE id = 'p1'"), 'nothing of the host was lost');
    }

    /**
     * Audit UZ4-1: a many-to-many add flushed inside the unit and rolled back. The owner is refreshed, so its
     * collection is the database's again, and the client's retry writes the link for real.
     */
    public function testARolledBackManyToManyAddIsWrittenByTheRetry(): void
    {
        $host  = $this->hostManager();
        $note  = new HostNote('n0', 'a');
        $label = new HostLabel('l1');
        $host->persist($note);
        $host->persist($label);
        $host->flush();

        $this->conflict($host, static function () use ($host, $note, $label): void {
            $note->labels->add($label);
            $host->flush();
        });

        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_note_label'), 'rolled back');
        self::assertCount(0, $note->labels, 'the owner\'s collection is the database\'s again');
        self::assertFalse(HostWriteProbe::hasScheduledChanges($host));

        $this->runner()->write(static function () use ($host, $note, $label): void {
            $note->labels->add($label);
            $host->flush();
        });

        self::assertSame(1, $this->int('SELECT COUNT(*) FROM host_note_label'), 'the retry writes the link');
    }

    /**
     * Audit UZ4-2: a child inserted through a cascade-persist inverse collection is rolled back. The owner's
     * collection is reset, so the host's next flush writes no phantom row and the next bundle write starts.
     */
    public function testARolledBackInverseCollectionInsertLeavesNoPhantomRow(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();
        $child = new HostEvent('child');

        $this->conflict($host, static function () use ($host, $note, $child): void {
            $child->note = $note;
            $note->events->add($child);
            $host->flush();
        });

        self::assertFalse($host->contains($child));
        self::assertTrue($host->contains($note));
        self::assertCount(0, $note->events, 'the inverse collection is the database\'s again');
        $host->flush();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM host_event'), 'the host\'s next flush writes nothing');
        self::assertSame('ok', $this->runner()->write(static fn (): string => 'ok'), 'the next bundle write starts');
    }

    /**
     * Audit UZ4-3: a versioned host entity gets its database version back, so the retry neither fails nor closes the
     * host's manager.
     */
    public function testARolledBackVersionedUpdateGetsItsVersionBack(): void
    {
        $host      = $this->hostManager();
        $versioned = new HostVersioned('v0', 'a');
        $host->persist($versioned);
        $host->flush();
        $version = $versioned->version;

        $this->conflict($host, static function () use ($host, $versioned): void {
            $versioned->text = 'b';
            $host->flush();
        });

        self::assertSame($version, $versioned->version, 'the database\'s version');
        self::assertSame('a', $versioned->text);

        $this->runner()->write(static function () use ($host, $versioned): void {
            $versioned->text = 'b';
            $host->flush();
        });

        self::assertTrue($host->isOpen());
        self::assertSame('b', $this->text("SELECT text FROM host_versioned WHERE id = 'v0'"), 'the retry writes it');
    }

    /**
     * Audit UZ4-4: a readonly generated id cannot be reset; the entity is still detached, the others are restored, and
     * the answer stays a 409.
     */
    public function testARolledBackInsertWithAReadonlyIdStillAnswersAConflict(): void
    {
        $host     = $this->hostManager();
        $readonly = new HostReadonly('readonly id');
        $event    = new HostEvent('generated id');

        $this->conflict($host, static function () use ($host, $readonly, $event): void {
            $host->persist($readonly);
            $host->persist($event);
            $host->flush();
        });

        self::assertFalse($host->contains($readonly));
        self::assertFalse($host->contains($event));
        self::assertNull($event->id, 'the other entity is still restored');
    }

    /**
     * Audit UZ4-5: detaching a rolled-back insert cascades (cascade detach) to an entity the host already had; that
     * entity is managed again.
     */
    public function testACascadeDetachNeverDetachesAnEntityTheHostHad(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();
        $event = new HostEvent('linked');

        $this->conflict($host, static function () use ($host, $note, $event): void {
            $event->note = $note;
            $host->persist($event);
            $host->flush();
        });

        self::assertFalse($host->contains($event));
        self::assertTrue($host->contains($note), 'the existing note stays managed');
        $note->text = 'edited later';
        $host->flush();
        self::assertSame('edited later', $this->text("SELECT text FROM host_note WHERE id = 'n0'"), 'and the host still writes it');
    }

    /**
     * Audit UZ3-2: the host's own transaction on its own connection is not the unit's: a port's flush inside it is
     * not rolled back with the unit. CONFLICT_002, the entity stays managed, the host's commit stores it.
     */
    public function testAHostFlushInsideTheHostsOwnTransactionIsConflict002(): void
    {
        $this->hostKind = Host::ddd();
        $host           = $this->hostManager();
        $note           = new HostNote('t1', 'inside the host transaction');
        $host->getConnection()->beginTransaction();

        try {
            $conflict = $this->conflict($host, static function () use ($host, $note): void {
                $host->persist($note);
                $host->flush();
            });
        } finally {
            $host->getConnection()->commit();
        }

        self::assertTrue($conflict->committed, 'not undone by the unit: CONFLICT_002');
        self::assertTrue($host->contains($note), 'not detached');
        self::assertSame(1, $this->hostInt($host, "SELECT COUNT(*) FROM host_note WHERE id = 't1'"), 'the host\'s commit stored it');
    }

    public function testAHostFlushThatCommittedOnItsOwnConnectionIsConflict002(): void
    {
        $this->hostKind = Host::ddd();
        $host           = $this->hostManager();
        $note           = new HostNote('n1', 'committed by the port');

        $conflict = $this->conflict($host, static function () use ($host, $note): void {
            $host->persist($note);
            $host->flush();
        });

        self::assertTrue($conflict->committed, 'CONFLICT_002: the host flush committed');
        self::assertSame(1, $this->hostInt($host, "SELECT COUNT(*) FROM host_note WHERE id = 'n1'"), 'never repeated');
        self::assertTrue($host->contains($note), 'a committed row stays managed');
    }

    public function testAHostManagerClosedAfterACommittedFlushIsConflict002(): void
    {
        $this->hostKind = Host::ddd();
        $host           = $this->hostManager();

        $conflict = $this->conflict($host, static function () use ($host): void {
            $host->persist(new HostNote('n1', 'committed by the port'));
            $host->flush();
            $host->close();
        });

        self::assertTrue($conflict->committed);
        self::assertSame(1, $this->hostInt($host, "SELECT COUNT(*) FROM host_note WHERE id = 'n1'"), 'not replayed twice');
    }

    /**
     * Runs $port in a unit whose first attempt ends in a deadlock and returns the conflict; exactly one attempt.
     *
     * @param Closure(): void $port
     */
    private function conflict(EntityManagerInterface $host, Closure $port): ConcurrencyConflict
    {
        $attempts = new AttemptCounter();
        $failure  = null;
        try {
            $this->runner()->write(static function () use ($attempts, $port): never {
                ++$attempts->count;
                $port();

                throw self::deadlock();
            });
        } catch (Throwable $e) {
            $failure = $e;
        }

        self::assertInstanceOf(ConcurrencyConflict::class, $failure, 'a 409, never a silent replay');
        self::assertInstanceOf(DeadlockException::class, $failure->getPrevious());
        self::assertSame(1, $attempts->count, 'not replayed');
        self::assertNotSame($host, $this->em());

        return $failure;
    }

    /**
     * A host note linked to two labels through its owning many-to-many, flushed.
     */
    private function noteWithLabels(EntityManagerInterface $host): HostNote
    {
        $note = new HostNote('n0', 'a');
        foreach (['l1', 'l2'] as $id) {
            $label = new HostLabel($id);
            $host->persist($label);
            $note->labels->add($label);
        }
        $host->persist($note);
        $host->flush();

        return $note;
    }

    /**
     * A host note with two kids in its orphan-removal collection, flushed.
     */
    private function noteWithKids(EntityManagerInterface $host): HostNote
    {
        $note = new HostNote('n0', 'a');
        foreach (['k1', 'k2'] as $id) {
            $kid       = new HostKid($id);
            $kid->note = $note;
            $note->kids->add($kid);
        }
        $host->persist($note);
        $host->flush();

        return $note;
    }

    private function hostManager(): EntityManagerInterface
    {
        $this->boot();
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host = $registry->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        HostSchema::reset($host);
        $this->enable();

        return $host;
    }

    private function hostInt(EntityManagerInterface $host, string $sql): int
    {
        $value = $host->getConnection()->fetchOne($sql);

        return is_numeric($value) ? (int) $value : -1;
    }

    private function runner(): DoctrineTransactionRunner
    {
        return $this->service(DoctrineTransactionRunner::class);
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException {
        }, null);
    }
}
