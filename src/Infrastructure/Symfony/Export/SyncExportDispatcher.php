<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export;

use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;

/**
 * Generates the export in the request (`register.export.async: sync`, or `auto` without Messenger).
 */
final readonly class SyncExportDispatcher implements ExportDispatcherInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private ExportGeneratorInterface $generator)
    {
    }

    /**
     * Generates at once.
     */
    public function dispatch(string $exportId): void
    {
        $this->generator->generate($exportId);
    }
}
