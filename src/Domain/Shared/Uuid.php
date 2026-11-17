<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * Checks the bundle's row ids: lowercase canonical UUID strings (36 characters). New rows are UUIDv7
 * (IdGeneratorInterface); migrated Beacon rows keep their v4.
 */
final class Uuid
{
    /** Canonical lowercase form. */
    public const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * Whether the value is a canonical lowercase UUID.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The value, or an exception.
     */
    public static function assert(string $value, string $what = 'id'): string
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException(sprintf('The %s is not a lowercase UUID.', $what));
        }

        return $value;
    }
}
