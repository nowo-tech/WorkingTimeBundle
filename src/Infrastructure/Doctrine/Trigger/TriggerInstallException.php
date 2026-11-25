<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger;

use Nowo\WorkingTimeBundle\Application\Register\Port\TriggerInstallRefusedException;
use Throwable;

/**
 * The database user may not create the triggers (contract section 6.4): nothing half-created is left. On MySQL /
 * MariaDB with binary logging the user also needs `SUPER` or `log_bin_trust_function_creators = 1`.
 */
final class TriggerInstallException extends TriggerInstallRefusedException
{
    /**
     * @param bool $mysql whether the platform is MySQL / MariaDB (the hint names the binlog privilege)
     */
    public function __construct(public readonly bool $mysql, Throwable $previous)
    {
        parent::__construct($mysql
            ? 'The register triggers could not be created: the database user needs the TRIGGER privilege and, with binary logging, SUPER or log_bin_trust_function_creators = 1.'
            : 'The register triggers could not be created: the database user needs the privilege to create triggers and functions.', 0, $previous);
    }
}
