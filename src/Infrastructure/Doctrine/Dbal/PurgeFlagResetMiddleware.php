<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware (contract sections 6.4 and 16): on every new MySQL / MariaDB connection clears the purge guard's
 * session variable, so a connection reused by a kept-alive worker never carries a purge flag a crash left set.
 */
final readonly class PurgeFlagResetMiddleware implements Middleware
{
    /**
     * Wraps the driver.
     */
    public function wrap(Driver $driver): Driver
    {
        return new PurgeFlagResetDriver($driver);
    }
}
