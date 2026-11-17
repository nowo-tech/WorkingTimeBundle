<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Exception;

use RuntimeException;

/**
 * A schedule change the rules refuse (a past row withdrawn, an unknown group or worker); the message is the translation key (`schedule.error.*`).
 */
final class ScheduleRefusedException extends RuntimeException implements WorkingTimeExceptionInterface
{
    /**
     * @param string $key translation key of the refusal
     */
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}
