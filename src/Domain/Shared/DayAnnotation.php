<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

/**
 * A mark another component puts on a worker's register day (contract section 8.2,
 * `RegisterDayAnnotationProviderInterface`): a public holiday (S2) or a leave day (S3). A full day off raises no
 * reminder and needs no clocking; a partial one (half-day or hours of leave) reduces the expected time. The label is
 * what the register shows (a holiday's public name; for medical leave *Absence – justified* to anyone without
 * `leave_manage`). Never carries a comment or a health detail.
 */
final readonly class DayAnnotation
{
    /** A public holiday. */
    public const HOLIDAY = 'holiday';

    /** A leave day. */
    public const LEAVE = 'leave';

    /**
     * Wires and checks the values.
     *
     * @param int $reducedSeconds expected time taken off the day (0 = none; a full day off ignores it)
     */
    public function __construct(
        public string $kind,
        public string $label,
        public bool $fullDay = true,
        public int $reducedSeconds = 0,
    ) {
        if ($kind !== self::HOLIDAY && $kind !== self::LEAVE) {
            throw new InvalidArgumentException('A day annotation is a holiday or a leave day.');
        }
        if ($reducedSeconds < 0) {
            throw new InvalidArgumentException('reducedSeconds is never negative.');
        }
    }
}
