<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger;

use Nowo\WorkingTimeBundle\Application\Register\Port\TriggerInspectorInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Throwable;

/**
 * Reads the installed triggers on the bundle's connection; a database that cannot be asked reports every trigger
 * missing (the settings page warns, `require` refuses).
 */
final readonly class DoctrineTriggerInspector implements TriggerInspectorInterface
{
    public function __construct(
        private DoctrineTransactionRunner $runner,
        private RegisterTriggerInstaller $installer,
    ) {
    }

    /**
     * The missing trigger names.
     */
    public function missing(): array
    {
        $connection = $this->runner->manager()->getConnection();
        try {
            return $this->installer->missing($connection);
        } catch (Throwable) {
            return $this->installer->names($connection->getDatabasePlatform());
        }
    }
}
