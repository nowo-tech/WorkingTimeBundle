<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

use function max;
use function sprintf;
use function substr;

/**
 * Retention of the register (spec 198 FR-024, contract section 6.5): the first kept local date is the first day of the
 * month `retention_years` (never fewer than four) before the current month. Older workdays are deleted with
 * everything that hangs from them.
 */
final class RetentionPolicy
{
    /**
     * The first kept date.
     */
    public static function firstKept(int $retentionYears, LocalDate $today): LocalDate
    {
        $years = max(RegisterSettingsInput::MIN_RETENTION_YEARS, $retentionYears);

        return new LocalDate(sprintf('%04d-%s-01', $today->year() - $years, substr($today->value, 5, 2)));
    }
}
