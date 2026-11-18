<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Event;

use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarId;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * Holidays were imported into a calendar (a file or a provider), dispatched after the commit. Ids and counts only.
 */
final readonly class HolidaysImported
{
    /**
     * Wires the values.
     */
    public function __construct(
        public TenantRef $tenant,
        public HolidayCalendarId $calendar,
        public string $source,
        public int $added,
        public int $removed,
    ) {
    }
}
