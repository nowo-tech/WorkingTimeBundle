<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * Life of an export row: pending → running (claimed by one generator) → ready | failed; ready → expired.
 */
enum ExportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready   = 'ready';
    case Failed  = 'failed';
    case Expired = 'expired';
}
