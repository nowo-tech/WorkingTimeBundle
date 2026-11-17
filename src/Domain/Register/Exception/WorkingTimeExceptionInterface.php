<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use Throwable;

/**
 * Marker of the bundle's own refusals.
 */
interface WorkingTimeExceptionInterface extends Throwable
{
}
