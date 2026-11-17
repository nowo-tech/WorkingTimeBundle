<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use PHPUnit\Framework\TestCase;

use const DATE_ATOM;

/**
 * Instants are written as their UTC wall clock and read back as UTC whatever PHP's default time zone (audit ST-H1).
 */
final class UtcDateTimeImmutableTypeTest extends TestCase
{
    public function testUtcBothWays(): void
    {
        $type     = new UtcDateTimeImmutableType();
        $platform = new PostgreSQLPlatform();
        $madrid   = new DateTimeImmutable('2026-11-18 09:00:00', new DateTimeZone('Europe/Madrid'));

        self::assertSame('2026-11-18 08:00:00', $type->convertToDatabaseValue($madrid, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertSame('2026-11-18T08:00:00+00:00', $type->convertToPHPValue('2026-11-18 08:00:00', $platform)?->format(DATE_ATOM));
        self::assertSame('2026-11-18T08:00:00+00:00', $type->convertToPHPValue('2026-11-18T08:00:00.000', $platform)?->format(DATE_ATOM), 'other stored forms are read in UTC too');
        self::assertSame('2026-11-18T08:00:00+00:00', $type->convertToPHPValue($madrid, $platform)?->format(DATE_ATOM));
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertSame(UtcDateTimeImmutableType::NAME, $type->getName());
    }
}
