<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared\Exception;

use Throwable;

/**
 * Marker of every refusal of the bundle, whatever the component (register, holidays, leave).
 */
interface WorkingTimeException extends Throwable
{
}
