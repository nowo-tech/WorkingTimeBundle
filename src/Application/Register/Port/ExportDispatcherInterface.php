<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

/**
 * Hands a requested export to its generator after the request committed: at once (`export.async: sync`, or `auto`
 * without Messenger) or through Messenger (`GenerateRegisterExportMessage`).
 */
interface ExportDispatcherInterface
{
    /**
     * Generates (or queues the generation of) the export.
     */
    public function dispatch(string $exportId): void;
}
