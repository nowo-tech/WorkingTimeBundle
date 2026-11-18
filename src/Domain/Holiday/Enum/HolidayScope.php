<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Enum;

/**
 * Where a holiday calendar applies (spec 204 FR-008): the country, a region (Spain: an autonomous community) or a
 * municipality. Beacon's `autonomous` reads as `regional` (contract section 5; migration note in UPGRADING).
 */
enum HolidayScope: string
{
    case National = 'national';
    case Regional = 'regional';
    case Local    = 'local';

    /** Accepted values in a CSV `scope` column (Beacon's `autonomous` included). */
    public static function fromImport(string $value): ?self
    {
        $value = strtolower(trim($value));

        return $value === 'autonomous' ? self::Regional : self::tryFrom($value);
    }

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'holiday.scope.' . $this->value;
    }
}
