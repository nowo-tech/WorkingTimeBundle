<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger;

use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the export's generation on Messenger (`register.export.async: messenger`, or `auto` with Messenger).
 */
final readonly class MessengerExportDispatcher implements ExportDispatcherInterface
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * Dispatches the message.
     */
    public function dispatch(string $exportId): void
    {
        $this->bus->dispatch(new GenerateRegisterExportMessage($exportId));
    }
}
