<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use Nowo\WorkingTimeBundle\Domain\Shared\Exception\WorkingTimeException;

/**
 * Marker of the bundle's own refusals.
 */
interface WorkingTimeExceptionInterface extends WorkingTimeException
{
}
