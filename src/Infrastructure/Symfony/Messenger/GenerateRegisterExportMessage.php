<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger;

/**
 * Asks the worker to generate a register export (contract section 12). Route it with `messenger.transport`.
 */
final readonly class GenerateRegisterExportMessage
{
    public function __construct(public string $exportId)
    {
    }
}
