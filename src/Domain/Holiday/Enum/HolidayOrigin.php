<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Enum;

/**
 * Where a holiday day came from (spec 204 FR-008). A provider import replaces only the days the same provider brought
 * earlier; a manual day (added or edited by hand) or a file day is never touched by an import. The stored values are
 * Beacon's (`file` for a CSV / ICS import), so migrated rows keep their meaning.
 */
enum HolidayOrigin: string
{
    case Manual       = 'manual';
    case Import       = 'file';
    case FestivosIo   = 'festivos_io';
    case OpenHolidays = 'openholidays';
    case Provider     = 'provider';

    /** The origin of a provider key (a host provider is `provider`). */
    public static function ofProvider(string $key): self
    {
        return match ($key) {
            'festivos_io'  => self::FestivosIo,
            'openholidays' => self::OpenHolidays,
            default        => self::Provider,
        };
    }

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'holiday.origin.' . $this->value;
    }
}
