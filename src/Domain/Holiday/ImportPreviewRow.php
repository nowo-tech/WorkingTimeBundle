<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;

/**
 * One row of an import preview (contract section 5): the date as read (`Y-m-d` when valid, the raw text otherwise),
 * the name, the status and, for provider rows, the scope and the source the provider cites.
 */
final readonly class ImportPreviewRow
{
    /**
     * Wires the values.
     */
    public function __construct(
        public string $date,
        public string $name,
        public ImportStatus $status,
        public ?HolidayScope $scope = null,
        public ?string $sourceRef = null,
        public ?string $sourceUrl = null,
    ) {
    }

    /** The same row with another status. */
    public function withStatus(ImportStatus $status): self
    {
        return new self($this->date, $this->name, $status, $this->scope, $this->sourceRef, $this->sourceUrl);
    }
}
