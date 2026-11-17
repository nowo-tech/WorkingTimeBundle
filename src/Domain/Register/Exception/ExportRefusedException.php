<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use RuntimeException;

/**
 * An export request the rules refuse; the message is the translation key (`export.error.*`).
 */
final class ExportRefusedException extends RuntimeException implements WorkingTimeExceptionInterface
{
    /**
     * @param string $key translation key of the refusal
     */
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}
