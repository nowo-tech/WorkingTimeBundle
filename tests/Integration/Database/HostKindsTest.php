<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostEvent;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Both host kinds (REQ-ARCH-001, contract section 17) and the single-entity-manager Flex host (`entity_manager: null`,
 * or the default manager's name; coordinator decision for S1): the register works on a DDD host (its own connection
 * and entity manager, another table prefix) and on the default entity manager, which the bundle never flushes outside its explicit transactions.
 */
final class HostKindsTest extends RegisterTestCase
{
    private ?Host $hostKind = null;

    protected function host(): Host
    {
        return $this->hostKind ?? Host::standard();
    }

    public function testTheRegisterWorksOnADddHost(): void
    {
        $this->hostKind = Host::ddd();
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->workDay();

        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
        self::assertSame(4, $this->int('SELECT COUNT(*) FROM ddd_working_time_revision'));
        self::assertSame([], $this->installer()->missing($this->connection()));
    }

    public function testTheRegisterWorksOnTheDefaultEntityManagerOfASingleEmHost(): void
    {
        $this->hostKind = Host::singleEntityManager();
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertSame($registry->getManager(), $this->em(), 'the bundle uses the default entity manager');

        $this->act(ClockAction::ClockIn);
        $this->act(ClockAction::ClockOut, 60);

        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
        self::assertSame(0, $this->em()->getUnitOfWork()->size(), 'nothing of the bundle left managed in the host unit of work');
    }

    /**
     * transaction-doctrine 1.7 `forManagers()` (audit UI2-1): on the private entity manager a replayed unit clears
     * only the bundle's manager; the host's open default manager keeps its entities. The replay is a deadlock on the
     * first attempt (on every database of the suite).
     */
    public function testAReplayOnThePrivateManagerKeepsTheHostsEntities(): void
    {
        $this->boot();
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host = $registry->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertNotSame($host, $this->em(), 'the standard host: the bundle has its private manager');
        $tool = new SchemaTool($host);
        $meta = [$host->getClassMetadata(HostNote::class), $host->getClassMetadata(HostEvent::class)];
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $note = new HostNote('n1', 'kept');
        $host->persist($note);
        $host->flush();
        $this->enable();

        $attempts = 0;
        $result   = $this->service(DoctrineTransactionRunner::class)->write(static function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException { }, null);
            }

            return 'written';
        });

        self::assertSame('written', $result);
        self::assertSame(2, $attempts, 'the deadlock was replayed');
        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($note), 'the host\'s entity stays managed across the replay');
        $tool->dropSchema($meta);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function sharedManagerConfigs(): iterable
    {
        yield 'entity_manager: null' => [[]];
        yield 'entity_manager: default (the default manager by name, audit ST Info)' => [['doctrine' => ['entity_manager' => 'default']]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('sharedManagerConfigs')]
    public function testASingleEmHostKeepsItsEntitiesAndItsPendingChanges(array $config): void
    {
        $this->hostKind = Host::singleEntityManager();
        $this->boot($config);
        $tool = new SchemaTool($this->em());
        $meta = [$this->em()->getClassMetadata(HostNote::class), $this->em()->getClassMetadata(HostEvent::class)];
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $this->enable();
        $this->actAs('ana', 'clock');
        $note = new HostNote('n1', 'draft');
        $this->em()->persist($note);
        $this->em()->flush();
        $note->text = 'edited by the host';

        self::assertSame('idle', $this->service(RegisterClockInterface::class)->state()->status->value, 'a bundle read');
        self::assertTrue($this->em()->contains($note), 'the host entity stays managed');
        try {
            $this->act(ClockAction::ClockIn);
            self::fail('A write while the host has pending changes is refused.');
        } catch (LogicException $e) {
            self::assertStringContainsString('will not flush the host', $e->getMessage());
        }
        $this->em()->flush();
        self::assertSame('working', $this->act(ClockAction::ClockIn)->status->value);
        self::assertTrue($this->em()->contains($note));
        self::assertSame('edited by the host', $this->text("SELECT text FROM host_note WHERE id = 'n1'"));
    }
}
