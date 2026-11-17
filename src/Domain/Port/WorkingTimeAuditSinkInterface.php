<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;

/**
 * Records `working_time.*` audit entries (contract section 8.2). Default: PsrLogAuditSink (one structured line).
 */
interface WorkingTimeAuditSinkInterface
{
    /**
     * Records the entry.
     */
    public function record(AuditEntry $entry): void;
}
