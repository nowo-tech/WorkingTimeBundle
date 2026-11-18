<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared\Exception;

use RuntimeException;

/**
 * A change the rules refuse; the message is the translation key of the refusal (domain `NowoWorkingTimeBundle`) and
 * the parameters fill it. Never carries personal data.
 */
abstract class RefusedException extends RuntimeException implements WorkingTimeException
{
    /**
     * @param string $key translation key of the refusal
     * @param array<string, int|string> $params translation parameters (`%max%`, …)
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
