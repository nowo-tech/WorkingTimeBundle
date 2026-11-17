<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Type;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

use function is_string;

/**
 * Every instant of the register is stored and read as **UTC** (audit ST-H1): the value is converted to UTC before it is
 * written and the stored wall clock is read back in UTC, whatever PHP's `date.timezone` is. Without it a host running
 * in `Europe/Madrid` read every instant shifted by one or two hours and every chain failed at its first link.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    /** Type name used by the mapping. */
    public const NAME = 'nowo_wt_utc_datetime';

    /**
     * The UTC wall clock of the instant.
     *
     * @param T $value
     *
     * @return (T is null ? null : string)
     *
     * @template T
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            $value = $value->setTimezone(new DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * The stored UTC wall clock as a UTC instant.
     *
     * @param T $value
     *
     * @return (T is null ? null : DateTimeImmutable)
     *
     * @template T
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value?->setTimezone(new DateTimeZone('UTC'));
        }
        $utc  = new DateTimeZone('UTC');
        $text = is_string($value) ? $value : '';
        $read = DateTimeImmutable::createFromFormat('!' . $platform->getDateTimeFormatString(), $text, $utc);

        return $read !== false ? $read : new DateTimeImmutable($text, $utc);
    }

    /**
     * The type name (DBAL 3).
     */
    public function getName(): string
    {
        return self::NAME;
    }
}
