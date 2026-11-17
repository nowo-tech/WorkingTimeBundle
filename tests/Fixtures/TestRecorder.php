<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use RuntimeException;

use function array_map;

/**
 * Recording notifier, audit sink and task-timer link of the test host.
 */
final class TestRecorder implements WorkingTimeNotifierInterface, WorkingTimeAuditSinkInterface, TaskTimerLinkInterface
{
    /** @var list<NoticeMessage> */
    public array $sent = [];

    /** @var list<AuditEntry> */
    public array $audit = [];

    /** @var list<array{string, string, DateTimeImmutable}> */
    public array $timers = [];

    public bool $failNotices = false;

    public bool $failTimer = false;

    public function send(NoticeMessage $message): void
    {
        if ($this->failNotices) {
            throw new RuntimeException('Notifier down.');
        }
        $this->sent[] = $message;
    }

    public function record(AuditEntry $entry): void
    {
        $this->audit[] = $entry;
    }

    public function stopRunning(TenantRef $tenant, WorkerRef $worker, DateTimeImmutable $at): void
    {
        if ($this->failTimer) {
            throw new RuntimeException('Timer down.');
        }
        $this->timers[] = [$tenant->value, $worker->value, $at];
    }

    /**
     * The audit keys recorded so far.
     *
     * @return list<string>
     */
    public function auditKeys(): array
    {
        return array_map(static fn (AuditEntry $entry): string => $entry->key, $this->audit);
    }
}
