<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger;

use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;

/**
 * Handles {@see GenerateRegisterExportMessage} (inside transaction-doctrine's write-unit middleware when the host adds it
 * to the bus).
 */
final readonly class GenerateRegisterExportHandler
{
    public function __construct(private ExportGeneratorInterface $generator)
    {
    }

    /**
     * Generates the export (a second delivery finds it claimed and does nothing).
     */
    public function __invoke(GenerateRegisterExportMessage $message): void
    {
        $this->generator->generate($message->exportId);
    }
}
