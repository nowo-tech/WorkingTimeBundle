<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

/**
 * What a holiday provider returned for a location and a year (contract section 8.2): the holidays by date, the place's
 * name when the provider gives one, the date of its last update, whether the year is still provisional (festivos.io
 * `local_status: pending`), the public URL that was read (the calendar's source note) and the attribution to show.
 */
final readonly class HolidayFetchResult
{
    /**
     * @param list<HolidayEntry> $entries by date, one per date
     */
    public function __construct(
        public array $entries,
        public string $sourceUrl,
        public ?string $place = null,
        public ?string $lastUpdated = null,
        public bool $provisional = false,
        public ?string $attribution = null,
    ) {
    }
}
