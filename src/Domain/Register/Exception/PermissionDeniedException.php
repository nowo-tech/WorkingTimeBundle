<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use RuntimeException;

/**
 * The actor lacks the permission inside an enabled tenant (contract section 13). Answers 403.
 */
final class PermissionDeniedException extends RuntimeException implements WorkingTimeExceptionInterface
{
    /**
     * The constant client-safe message.
     */
    public function __construct()
    {
        parent::__construct('Forbidden.');
    }
}
