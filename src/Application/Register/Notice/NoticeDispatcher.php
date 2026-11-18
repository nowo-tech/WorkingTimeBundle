<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Notice;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAnnotations;
use Nowo\WorkingTimeBundle\Application\Register\RegisterReader;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\EffectiveSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\MonthSummary;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function intdiv;

/**
 * The notices run (`nowo:working-time:notices`, every 5 minutes; spec 198 FR-025 / FR-026):
 *
 * - plans the clock reminders (opted-in workers of tenants that allow them, ± the window of the scheduled start / end,
 *   only when the day's state calls for it) and the monthly summary (the previous month, on the first working day,
 *   unless the worker opted out and the tenant did not make it mandatory) into the outbox, deduplicated;
 * - delivers the outbox notices whose `deliverable_from` has passed, oldest first, each marked sent with a
 *   conditional update; a notifier failure leaves the notice for the next run.
 *
 * Bounded per run (`$budget` notices delivered). Stateless.
 */
final readonly class NoticeDispatcher
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterStoreInterface $store,
        private RegisterReader $reader,
        private ScheduleResolver $schedules,
        private NoticeOutbox $outbox,
        private WorkerDirectoryInterface $directory,
        private WorkingTimeNotifierInterface $notifier,
        private TransactionRunnerInterface $runner,
        private ClockInterface $clock,
        private ?LoggerInterface $logger = null,
        private int $windowMinutes = 15,
        private bool $monthlySummary = true,
        private int $budget = 500,
        private ?RegisterAnnotations $annotations = null,
    ) {
    }

    /**
     * Plans and delivers.
     *
     * @return array{queued: int, sent: int, failed: int}
     */
    public function run(): array
    {
        $now    = $this->clock->now();
        $queued = 0;
        foreach ($this->runner->read(fn (): array => $this->store->allSettings()) as $settings) {
            if ($settings->isEnabled()) {
                $queued += $this->plan($settings, $now);
            }
        }
        [$sent, $failed] = $this->deliver($now);

        return ['queued' => $queued, 'sent' => $sent, 'failed' => $failed];
    }

    private function plan(RegisterSettings $settings, DateTimeImmutable $now): int
    {
        $tenant = new TenantRef($settings->getTenantId());
        $queued = 0;
        foreach ($this->directory->workers($tenant) as $worker) {
            if (!$worker->active) {
                continue;
            }
            $queued += $this->runner->write(function () use ($settings, $worker, $now): int { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $preference     = $this->store->preference($worker->worker);
                [$date, $today] = $this->schedules->at($settings, $worker, $now);
                $queued         = 0;
                if ($settings->remindersAllowed() && $preference?->wantsReminders() === true) {
                    $queued += $this->reminder($settings, $worker, $date, $today, $now) ? 1 : 0;
                }
                $wantsSummary = $settings->monthlySummaryMandatory() || ($preference?->wantsMonthlySummary() ?? true);
                if ($this->monthlySummary && $wantsSummary && $today->isWorkingDay() && $this->firstWorkingDay($settings, $worker, $date)) {
                    $queued += $this->summary($settings, $worker, $date, $now) ? 1 : 0;
                }

                return $queued;
            });
        }

        return $queued;
    }

    private function reminder(RegisterSettings $settings, WorkerProfile $worker, LocalDate $date, DaySchedule $schedule, DateTimeImmutable $now): bool
    {
        $side = HoursWindow::reminder($date, $schedule, $now, $this->windowMinutes);
        if ($side === null || $this->annotations?->isDayOff($settings, $worker, $date) === true) {
            // A public holiday (or a full day of leave) asks for no clocking.
            return false;
        }
        $tenant  = new TenantRef($settings->getTenantId());
        $workday = $this->store->workdayOn($tenant, $worker->worker, $date);
        $record  = $workday instanceof Workday ? $this->reader->one($workday, $now) : null;
        $working = $record?->openSegment() instanceof EffectiveSegment;
        if (($side === 'in' && $record instanceof DayRecord && $record->segments !== []) || ($side === 'out' && !$working)) {
            return false;
        }
        $kind = $side === 'in' ? RegisterNotice::REMINDER_IN : RegisterNotice::REMINDER_OUT;

        return $this->outbox->queue($settings, $worker->worker, $kind, ['date' => $date->value], null, $kind . ':' . $worker->worker->value . ':' . $date->value, $now);
    }

    private function firstWorkingDay(RegisterSettings $settings, WorkerProfile $worker, LocalDate $date): bool
    {
        $first = null;
        $offs  = $this->annotations?->between($settings, $worker, new LocalDate($date->month() . '-01'), $date) ?? [];
        foreach ($this->schedules->range($settings, $worker, new LocalDate($date->month() . '-01'), $date) as $day => $schedule) {
            $off = false;
            foreach ($offs[$day] ?? [] as $annotation) {
                $off = $off || $annotation->fullDay;
            }
            if ($schedule->isWorkingDay() && !$off) {
                $first = $day;
                break;
            }
        }

        return $first === $date->value;
    }

    private function summary(RegisterSettings $settings, WorkerProfile $worker, LocalDate $date, DateTimeImmutable $now): bool
    {
        $lastDay = (new LocalDate($date->month() . '-01'))->plusDays(-1);
        $month   = $lastDay->month();
        $key     = RegisterNotice::MONTHLY_SUMMARY . ':' . $worker->worker->value . ':' . $month;
        $tenant  = new TenantRef($settings->getTenantId());
        $summary = MonthSummary::of($this->reader->worker($tenant, $worker->worker, new LocalDate($month . '-01'), $lastDay, $now));

        return $this->outbox->queue($settings, $worker->worker, RegisterNotice::MONTHLY_SUMMARY, [
            'month'    => $month,
            'worked'   => MonthSummary::hours($summary->workedSeconds),
            'expected' => MonthSummary::hours($summary->expectedSeconds),
            'days'     => $summary->daysWorked,
            'missing'  => $summary->missingClockOuts,
            'overtime' => intdiv($summary->dailyOvertimeSeconds, 60),
        ], null, $key, $now);
    }

    /**
     * @return array{int, int}
     */
    private function deliver(DateTimeImmutable $now): array
    {
        $sent   = 0;
        $failed = 0;
        foreach ($this->runner->read(fn (): array => $this->store->dueNotices($now, $this->budget)) as $notice) {
            $tenant   = new TenantRef($notice->getTenantId());
            $worker   = new WorkerRef($notice->getWorkerId());
            $profile  = $this->directory->worker($tenant, $worker);
            $settings = $profile instanceof WorkerProfile ? $this->runner->read(fn (): ?RegisterSettings => $this->store->settings($tenant)) : null;
            if ($settings instanceof RegisterSettings && $profile instanceof WorkerProfile) {
                // A late run (cron delay, notifier outage) never delivers outside the recipient's hours (audit ST-L2).
                $at = $this->runner->read(fn (): DateTimeImmutable => $this->outbox->deliverableFrom($settings, $profile, $now));
                if ($at > $now) {
                    $this->runner->write(fn (): bool => $this->store->deferNotice($notice->getId(), $at));

                    continue;
                }
            }
            try {
                if ($profile instanceof WorkerProfile && $profile->worker->equals($worker)) {
                    $this->notifier->send(new NoticeMessage($tenant, $worker, $notice->getKind(), $notice->getParams(), $notice->getActionPath(), $profile->locale));
                }
                $sent += $this->runner->write(fn (): bool => $this->store->markNoticeSent($notice->getId(), $this->clock->now())) ? 1 : 0;
            } catch (Throwable $e) {
                ++$failed;
                $this->logger?->warning('Working-time notice not delivered; it stays in the outbox.', ['notice' => $notice->getId(), 'kind' => $notice->getKind(), 'exception' => $e::class]);
            }
        }

        return [$sent, $failed];
    }
}
