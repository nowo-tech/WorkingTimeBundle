<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

/**
 * Installs, removes and checks the append-only triggers of the register on the bundle's connection (contract section
 * 6.4), for the `nowo:working-time:triggers:*` commands. The container wires
 * `Infrastructure\Doctrine\Trigger\DoctrineRegisterTriggers`.
 */
interface RegisterTriggersInterface
{
    /**
     * Creates the triggers (dropping existing ones first) and returns how many there are; on any failure removes what
     * it created.
     *
     * @throws TriggerInstallRefusedException when the database user may not create them
     */
    public function install(): int;

    /**
     * Drops the triggers (and the PostgreSQL functions).
     */
    public function uninstall(): void;

    /**
     * The names of the triggers that are missing (empty: all installed).
     *
     * @return list<string>
     */
    public function missing(): array;
}
