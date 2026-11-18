<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;

/**
 * An import preview (spec 204 FR-008): the rows with their status, or the translation key of the reason the file was
 * refused as a whole; for a provider, its result (place, provisional year, attribution).
 */
final readonly class ImportPreview
{
    /**
     * @param list<ImportPreviewRow> $rows by date
     * @param string|null $error translation key (`holiday.import.error.*`)
     */
    public function __construct(
        public array $rows,
        public ?string $error = null,
        public ?HolidayFetchResult $fetched = null,
    ) {
    }

    /**
     * The rows of a status.
     *
     * @return list<ImportPreviewRow>
     */
    public function with(ImportStatus $status): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            if ($row->status === $status) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
