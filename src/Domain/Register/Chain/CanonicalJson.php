<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Chain;

use function array_is_list;
use function array_map;
use function is_array;
use function json_encode;
use function ksort;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * The one JSON form a chain link is hashed in (spec 198 FR-018; Beacon's `CanonicalJson`, byte for byte): object keys
 * sorted at every level, lists kept in order, no escaped slashes or Unicode, zero fractions kept.
 */
final class CanonicalJson
{
    /**
     * The canonical bytes.
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
