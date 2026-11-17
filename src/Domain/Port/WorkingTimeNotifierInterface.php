<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;

/**
 * Delivers one notice (contract section 8.2). The bundle decides **when** (inside the recipient's schedule, through
 * the outbox); the notifier only delivers. A failure throws: the outbox keeps the notice for the next run.
 */
interface WorkingTimeNotifierInterface
{
    /**
     * Delivers the notice.
     */
    public function send(NoticeMessage $message): void;
}
