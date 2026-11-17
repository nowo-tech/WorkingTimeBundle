<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;

/**
 * Notifier that delivers nothing (no mailer and no host notifier): the outbox still records and dedupes; the settings
 * page warns.
 */
final readonly class NullNotifier implements WorkingTimeNotifierInterface
{
    /**
     * Drops the notice.
     */
    public function send(NoticeMessage $message): void
    {
    }
}
