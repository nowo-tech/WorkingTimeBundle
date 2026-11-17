<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http;

use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;

/**
 * Worker mode (contract section 16, REQ-RUNTIME-002): clears the bundle's entity manager at the end of every request
 * and console command (and resets it when a failure closed it), so no managed entity and no broken manager survive
 * into the next request of a kept-alive kernel.
 */
final readonly class EntityManagerHygieneListener
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * `kernel.terminate`, `kernel.finish_request` (main request) and `console.terminate`.
     */
    public function clear(): void
    {
        $this->runner->release($this->runner->manager()); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }
}
