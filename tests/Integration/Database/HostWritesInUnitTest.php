<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Tests\Fixtures\AttemptCounter;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostEvent;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostNote;
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

    public function testAHostUpdateFlushedInsideTheUnitIsNotReplayed(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();

        $conflict = $this->conflict($host, static function () use ($host, $note): void {
            $note->text = 'b';
            $host->flush();
        });

        self::assertFalse($conflict->committed, 'the update went with the rollback: CONFLICT_001');
        self::assertSame('a', $this->text("SELECT text FROM host_note WHERE id = 'n0'"), 'never replayed over a lost write');
        self::assertTrue($host->contains($note), 'an entity the host had before stays managed');
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

    public function testAnUnflushedHostUpdateIsNotReplayed(): void
    {
        $host = $this->hostManager();
        $note = new HostNote('n0', 'a');
        $host->persist($note);
        $host->flush();

        $conflict = $this->conflict($host, static function () use ($note): void {
            $note->text = 'changed, not flushed';
        });

        self::assertFalse($conflict->committed);
        self::assertTrue($host->contains($note), 'the host keeps its own change to flush or drop');
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

    private function hostManager(): EntityManagerInterface
    {
        $this->boot();
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host = $registry->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $tool = new SchemaTool($host);
        $meta = [$host->getClassMetadata(HostNote::class), $host->getClassMetadata(HostEvent::class)];
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
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
