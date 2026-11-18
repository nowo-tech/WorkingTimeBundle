<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Exception;

use Nowo\WorkingTimeBundle\Domain\Shared\Exception\RefusedException;

/**
 * A holiday change the rules refuse (`holiday.*.error.*` translation keys): a bad name or year, a duplicate date, a
 * provider that failed (one translated error per provider, nothing saved).
 */
final class HolidayRefusedException extends RefusedException
{
}
