<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared\Exception;

use RuntimeException;

/**
 * The actor lacks the permission inside an enabled tenant (contract section 13). Answers 403.
 */
class AccessDeniedException extends RuntimeException implements WorkingTimeException
{
    /**
     * The constant client-safe message.
     */
    public function __construct()
    {
        parent::__construct('Forbidden.');
    }
}
