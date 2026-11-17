<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;

/**
 * Collation of the identifier and reference columns on MySQL / MariaDB (contract section 6.1, rule LQ): binary and
 * NO PAD where the server has one, so `usera` is not `UserA` and `u1 ` is not `u1`: `utf8mb4_0900_bin` (MySQL 8),
 * `utf8mb4_nopad_bin` (MariaDB), `utf8mb4_bin` on older MySQL. Other platforms compare exactly already (null).
 *
 * @internal
 */
final class BinaryCollation
{
    /** MySQL 8 NO PAD binary collation. */
    public const MYSQL_8 = 'utf8mb4_0900_bin';

    /** MariaDB NO PAD binary collation. */
    public const MARIADB = 'utf8mb4_nopad_bin';

    /** PAD SPACE binary collation of older MySQL servers. */
    public const FALLBACK = 'utf8mb4_bin';

    /**
     * The collation for the platform, or null when it is not MySQL / MariaDB.
     */
    public static function for(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB,
            $platform instanceof MySQL80Platform       => self::MYSQL_8,
            $platform instanceof AbstractMySQLPlatform => self::FALLBACK,
            default                                    => null,
        };
    }
}
