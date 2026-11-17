<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Shared;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The events of one call, dispatched only after its commit (contract section 7): a local buffer passed down, never
 * service state, so a rollback (or a replay) drops what the failed attempt collected.
 */
final class AfterCommitEvents
{
    /** @var list<object> */
    private array $events = [];

    /**
     * Keeps an event for after the commit.
     */
    public function add(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Forgets the collected events (a replayed attempt starts empty).
     */
    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * Dispatches and forgets the events.
     */
    public function dispatch(?EventDispatcherInterface $dispatcher): void
    {
        $events       = $this->events;
        $this->events = [];
        foreach ($events as $event) {
            $dispatcher?->dispatch($event);
        }
    }
}
