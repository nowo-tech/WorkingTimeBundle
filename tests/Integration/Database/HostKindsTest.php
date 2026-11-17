<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;

/**
 * Both host kinds (REQ-ARCH-001, contract section 17) and the single-entity-manager Flex host (`entity_manager: null`,
 * coordinator decision for S1): the register works on a DDD host (its own connection and entity manager, another table
 * prefix) and on the default entity manager, which the bundle never flushes outside its explicit transactions.
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
        self::assertSame(4, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ddd_working_time_revision'));
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
        self::assertSame(0, $this->em()->getUnitOfWork()->size(), 'nothing left managed in the host unit of work');
    }
}
