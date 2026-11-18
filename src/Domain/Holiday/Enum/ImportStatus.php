<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Enum;

/**
 * Status of one row of an import preview (contract section 5 `ImportPreviewRow`; spec 204 FR-008):
 *
 * - file imports: `new` (added on confirm), `duplicate` (the calendar or an earlier row has the date), `other_scope`
 *   (the CSV scope is not the calendar's), `invalid` (bad date, empty or long name, outside the calendar's year);
 * - provider imports (Beacon's sync): `new`, `unchanged` (an earlier day of the same provider, kept), `duplicate` (a day
 *   from another origin on that date, kept, not replaced) and `removed` (an earlier day of the same provider it no
 *   longer lists: removed on confirm).
 */
enum ImportStatus: string
{
    case New        = 'new';
    case Duplicate  = 'duplicate';
    case OtherScope = 'other_scope';
    case Invalid    = 'invalid';
    case Unchanged  = 'unchanged';
    case Removed    = 'removed';

    /** Translation key of the label. */
    public function labelKey(): string
    {
        return 'holiday.import.status.' . $this->value;
    }
}
