<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

/**
 * New row ids: lowercase canonical UUIDv7 strings. Default: SymfonyUuidGenerator.
 */
interface IdGeneratorInterface
{
    /**
     * A new id.
     */
    public function generate(): string;
}
