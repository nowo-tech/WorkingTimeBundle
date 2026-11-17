<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use SensitiveParameter;

use function in_array;
use function is_string;
use function str_contains;

/**
 * Clears the purge guard's session variable right after a MySQL / MariaDB connection opens.
 */
final class PurgeFlagResetDriver extends AbstractDriverMiddleware
{
    /** DBAL drivers of MySQL / MariaDB. */
    public const MYSQL_DRIVERS = ['pdo_mysql', 'mysqli'];

    /**
     * Connects, then clears the variable on MySQL / MariaDB.
     *
     * @param array<string, mixed> $params
     */
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params); // @phpstan-ignore argument.type
        if (self::isMysql($params)) {
            $connection->exec('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = NULL');
        }

        return $connection;
    }

    /**
     * Whether the connection parameters are MySQL / MariaDB ones (`driver`, or a MySQL `driverClass`).
     *
     * @param array<string, mixed> $params
     */
    public static function isMysql(array $params): bool
    {
        $driver = $params['driver'] ?? null;
        $class  = $params['driverClass'] ?? null;

        return (is_string($driver) && in_array($driver, self::MYSQL_DRIVERS, true)) || (is_string($class) && str_contains($class, 'MySQL'));
    }
}
