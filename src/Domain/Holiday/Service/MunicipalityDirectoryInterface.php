<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Service;

use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;

/**
 * Spain's municipalities and their INE codes (contract section 7): the town search of the festivos.io import. A
 * search reads the cache only and never calls out; {@see self::refresh()} (an admin action) fetches the list.
 */
interface MunicipalityDirectoryInterface
{
    /**
     * Up to `$limit` towns matching the query: digits match the INE code's start; words the town's or the province's
     * name, accents and case aside (towns whose name starts with it first). Empty while the list is not cached.
     *
     * @return list<array{value: string, text: string}>
     */
    public function search(string $query, int $limit = 20): array;

    /** The town of an INE code (`Madrid (Madrid)`), from the cache only. */
    public function name(string $ineCode): ?string;

    /** Whether the list is in the cache. */
    public function isLoaded(): bool;

    /**
     * Fetches the list and caches it; returns how many towns it holds.
     *
     * @throws HolidayRefusedException `holiday.provider.festivos_io.error`
     */
    public function refresh(): int;
}
