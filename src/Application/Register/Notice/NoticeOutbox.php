<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Notice;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function hash;

/**
 * Queues notices in the outbox inside the caller's write (spec 198 FR-025, spec 241 T018): a dedupe key per notice
 * (a notice is never queued twice) and the first instant inside the recipient's hours as `deliverable_from`. A
 * rollback drops the notice with the rest of the unit (a stale decision notifies nobody).
 */
final readonly class NoticeOutbox
{
    /** Days ahead looked at for the recipient's next hours. */
    public const LOOKAHEAD_DAYS = 8;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterStoreInterface $store,
        private WorkerDirectoryInterface $directory,
        private ScheduleResolver $schedules,
        private IdGeneratorInterface $ids,
        private int $windowMinutes = 15,
        private string $defaultHours = '09:00-18:00',
    ) {
    }

    /**
     * The first instant at or after `$now` inside the recipient's hours ± the window (their schedule's ranges, else the
     * default hours on weekdays).
     */
    public function deliverableFrom(RegisterSettings $settings, WorkerProfile $profile, DateTimeImmutable $now): DateTimeImmutable
    {
        $from = LocalDate::of($now, $this->schedules->forDate($settings, $profile, LocalDate::of($now, new DateTimeZone('UTC')))->zone());
        $days = HoursWindow::withDefaultHours($this->schedules->range($settings, $profile, $from->plusDays(-1), $from->plusDays(self::LOOKAHEAD_DAYS)), $this->defaultHours);

        return HoursWindow::nextDeliverable($days, $now, $this->windowMinutes);
    }

    /**
     * Queues a notice; false when one with the dedupe key exists or the recipient is not a member.
     *
     * @param array<string, scalar|null> $params
     */
    public function queue(RegisterSettings $settings, WorkerRef $recipient, string $kind, array $params, ?string $actionPath, string $dedupeKey, DateTimeImmutable $now): bool
    {
        $tenant = new TenantRef($settings->getTenantId());
        $key    = hash('sha256', $tenant->value . "\x1F" . $dedupeKey);
        if ($this->store->hasNotice($key)) {
            return false;
        }
        $profile = $this->directory->worker($tenant, $recipient);
        if (!$profile instanceof WorkerProfile || !$profile->worker->equals($recipient)) {
            return false;
        }

        return $this->store->addNotice(RegisterNotice::queue( // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->ids->generate(),
            $tenant->value,
            $recipient->value,
            $kind,
            $params,
            $actionPath,
            $key,
            $this->deliverableFrom($settings, $profile, $now),
            $now,
        ));
    }
}
