<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;

/**
 * The actor lacks the permission inside an enabled tenant (contract section 13). Answers 403.
 */
final class PermissionDeniedException extends AccessDeniedException implements WorkingTimeExceptionInterface
{
}
