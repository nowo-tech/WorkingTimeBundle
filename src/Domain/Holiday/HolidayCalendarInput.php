<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;

/**
 * The editable fields of a holiday calendar.
 */
final readonly class HolidayCalendarInput
{
    /**
     * Wires the values (validated by the calendar).
     */
    public function __construct(
        public string $name,
        public HolidayScope $scope,
        public int $year,
        public ?string $sourceNote = null,
        public ?HolidayLocation $location = null,
    ) {
    }
}
