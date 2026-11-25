<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

use RuntimeException;

/**
 * The database user may not create the append-only triggers (contract section 6.4); nothing half-created is left. The
 * Doctrine installer throws its subclass `Infrastructure\Doctrine\Trigger\TriggerInstallException`.
 */
class TriggerInstallRefusedException extends RuntimeException
{
}
