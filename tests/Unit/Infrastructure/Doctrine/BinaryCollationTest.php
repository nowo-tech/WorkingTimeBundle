<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\BinaryCollation;
use PHPUnit\Framework\TestCase;

/**
 * The binary collation chosen per database platform.
 */
final class BinaryCollationTest extends TestCase
{
    public function testCollationPerPlatform(): void
    {
        self::assertSame('utf8mb4_nopad_bin', BinaryCollation::for(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', BinaryCollation::for(new MySQL80Platform()));
        self::assertSame('utf8mb4_bin', BinaryCollation::for(new MySQLPlatform()));
        self::assertNull(BinaryCollation::for(new PostgreSQLPlatform()));
        self::assertNull(BinaryCollation::for(new SQLitePlatform()));
    }
}
