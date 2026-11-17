<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use mysqli;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use PDO;
use SensitiveParameter;

/**
 * Clears the purge guard's session variable right after a MySQL / MariaDB connection opens.
 */
final class PurgeFlagResetDriver extends AbstractDriverMiddleware
{
    /**
     * Connects, then clears the variable on MySQL / MariaDB.
     *
     * @param array<string, mixed> $params
     */
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params);
        if (self::isMysql($connection->getNativeConnection())) {
            $connection->exec('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = NULL');
        }

        return $connection;
    }

    /**
     * Whether the native connection is MySQL / MariaDB (PDO mysql or mysqli).
     */
    public static function isMysql(mixed $native): bool
    {
        return $native instanceof mysqli || ($native instanceof PDO && $native->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
    }
}
