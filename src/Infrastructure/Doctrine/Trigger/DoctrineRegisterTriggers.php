<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger;

use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterTriggersInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;

use function count;

/**
 * Default {@see RegisterTriggersInterface}: {@see RegisterTriggerInstaller} on the connection of the bundle's entity
 * manager. Stateless (worker mode): the connection is read on every call.
 */
final readonly class DoctrineRegisterTriggers implements RegisterTriggersInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private DoctrineTransactionRunner $runner,
        private RegisterTriggerInstaller $installer,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function install(): int
    {
        $connection = $this->runner->manager()->getConnection();
        $this->installer->install($connection);

        return count($this->installer->names($connection->getDatabasePlatform()));
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(): void
    {
        $this->installer->uninstall($this->runner->manager()->getConnection());
    }

    /**
     * {@inheritDoc}
     */
    public function missing(): array
    {
        return $this->installer->missing($this->runner->manager()->getConnection());
    }
}
