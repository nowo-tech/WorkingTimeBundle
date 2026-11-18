<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use InvalidArgumentException;

use function preg_match;
use function str_starts_with;
use function strtoupper;

/**
 * Where a provider looks holidays up (contract section 5): a country (ISO 3166-1 alpha-2), optionally a subdivision
 * (ISO 3166-2, `ES-MD`) and a Spanish municipality (INE code, five digits). No personal data ever goes to a provider:
 * this and the year are all it is sent.
 */
final readonly class HolidayLocation
{
    /** ISO 3166-1 alpha-2 country. */
    public string $country;

    /** ISO 3166-2 subdivision (`ES-MD`), or null. */
    public ?string $subdivision;

    /**
     * @param string $country ISO 3166-1 alpha-2 (case folded to capitals)
     * @param string|null $subdivision ISO 3166-2 first-level subdivision inside the country (`ES-MD`, at most 6 characters)
     * @param string|null $municipality INE municipality code (`^\d{5}$`)
     */
    public function __construct(string $country, ?string $subdivision = null, public ?string $municipality = null)
    {
        $country = strtoupper($country);
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException('holiday.location.error.country');
        }
        $subdivision = $subdivision === null || $subdivision === '' ? null : strtoupper($subdivision);
        if ($subdivision !== null && (preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/D', $subdivision) !== 1 || !str_starts_with($subdivision, $country . '-'))) {
            throw new InvalidArgumentException('holiday.location.error.subdivision');
        }
        if ($municipality !== null && !self::isMunicipalityCode($municipality)) {
            throw new InvalidArgumentException('holiday.location.error.municipality');
        }
        $this->country     = $country;
        $this->subdivision = $subdivision;
    }

    /** A Spanish municipality's INE code: five digits. */
    public static function isMunicipalityCode(string $code): bool
    {
        return preg_match('/^\d{5}$/D', $code) === 1;
    }
}
