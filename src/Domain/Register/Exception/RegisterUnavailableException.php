<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use RuntimeException;

/**
 * The register is not there for this call (contract section 11.3): no tenant, the feature off for the tenant, not a member, or a record of another tenant or worker. Answers 404, never revealing which.
 */
final class RegisterUnavailableException extends RuntimeException implements WorkingTimeExceptionInterface
{
    /**
     * The constant client-safe message.
     */
    public function __construct()
    {
        parent::__construct('Not found.');
    }
}
