<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

/**
 * Thresholds of the register's flags (configuration `register.*`, Beacon's defaults): a client time arriving more than
 * `lateSeconds` after it happened is *late*; an open segment `missingClockOutGraceSeconds` past the scheduled end (or
 * open `longDaySeconds` since the first start) is a *missing clock-out*; a day longer than `longDaySeconds` is long.
 */
final readonly class RegisterRules
{
    /** Art. 34.4 ET: the break rule applies to a day of more than six hours. */
    public const BREAK_RULE_AFTER = 6 * 3_600;

    public function __construct(
        public int $lateSeconds = 600,
        public int $missingClockOutGraceSeconds = 7_200,
        public int $longDaySeconds = 57_600,
    ) {
    }
}
