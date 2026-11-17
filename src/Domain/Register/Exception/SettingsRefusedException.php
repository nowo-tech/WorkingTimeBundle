<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use InvalidArgumentException;

/**
 * A settings value out of range; carries the field and the translation key (`settings.error.*`).
 */
final class SettingsRefusedException extends InvalidArgumentException implements WorkingTimeExceptionInterface
{
    /**
     * @param string $field the input field
     * @param string $key translation key of the refusal
     */
    public function __construct(public readonly string $field, public readonly string $key)
    {
        parent::__construct($key);
    }
}
