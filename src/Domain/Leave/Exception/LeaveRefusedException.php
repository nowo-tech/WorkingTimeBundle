<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Exception;

use Nowo\WorkingTimeBundle\Domain\Shared\Exception\RefusedException;

/**
 * A leave change the rules refuse (`leave.*.error.*` translation keys): a bad type, an allowance under the legal
 * minimum, a carry-over over the type's maximum, a request range the counter cannot cost.
 */
final class LeaveRefusedException extends RefusedException
{
}
