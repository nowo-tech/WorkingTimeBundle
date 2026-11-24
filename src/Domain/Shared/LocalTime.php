<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Formats an instant in a local time zone for display and exports (the register CSV, the PDF report and the pages
 * share it, so they always agree).
 */
final class LocalTime
{
    /**
     * The instant in `$zone` as `$format` (default `H:i`), or '' when there is none.
     */
    public static function format(?DateTimeImmutable $at, DateTimeZone $zone, string $format = 'H:i'): string
    {
        return $at?->setTimezone($zone)->format($format) ?? '';
    }
}
