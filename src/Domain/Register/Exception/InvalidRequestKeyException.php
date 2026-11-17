<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use InvalidArgumentException;

/**
 * A clock action's request key is empty, too long or not visible ASCII (Beacon REQ-SEC-004 F6). Answers 400.
 */
final class InvalidRequestKeyException extends InvalidArgumentException implements WorkingTimeExceptionInterface
{
    /**
     * The constant client-safe message.
     */
    public function __construct()
    {
        parent::__construct('Invalid request key.');
    }
}
