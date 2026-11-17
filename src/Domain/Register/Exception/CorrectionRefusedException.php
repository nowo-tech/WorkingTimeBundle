<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use RuntimeException;

/**
 * A correction (request, decision, record, answer) the rules refuse; the message is the translation key (`correction.error.*`).
 */
final class CorrectionRefusedException extends RuntimeException implements WorkingTimeExceptionInterface
{
    /**
     * @param string $key translation key of the refusal
     */
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}
