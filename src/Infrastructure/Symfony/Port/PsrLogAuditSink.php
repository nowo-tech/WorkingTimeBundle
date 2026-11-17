<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default audit sink: one structured `info` line per entry (key, tenant, actor, subject, ids and counts; never free
 * text).
 */
final readonly class PsrLogAuditSink implements WorkingTimeAuditSinkInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Logs the entry.
     */
    public function record(AuditEntry $entry): void
    {
        $this->logger->info('Working-time audit: ' . $entry->key, [
            'tenant'  => $entry->tenant->value,
            'actor'   => $entry->actor?->value,
            'subject' => $entry->subject?->value,
        ] + $entry->context);
    }
}
