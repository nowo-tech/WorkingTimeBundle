<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared\Exception;

use RuntimeException;

/**
 * The feature is not there for this call (contract section 11.3): no tenant, the component off (for the tenant), not a
 * member, or a record of another tenant or worker. Answers 404, never revealing which. The component exceptions
 * (`RegisterUnavailableException`, …) extend it, so one handler maps them all.
 */
class NotAvailableException extends RuntimeException implements WorkingTimeException
{
    /**
     * The constant client-safe message.
     */
    public function __construct()
    {
        parent::__construct('Not found.');
    }
}
