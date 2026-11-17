<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\AfterCommitEvents;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Event\ClockActionRecorded;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ClockConflictException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Register\Read\EffectiveSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterRules;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\IpPrefix;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function in_array;

/**
 * The register's clock (spec 198 FR-008 / FR-011, contract section 9.1).
 *
 * - States idle → working ↔ on break → closed; a closed day may reopen (split shift). An action the state does not
 *   allow throws {@see ClockConflictException} (409 with the state).
 * - Server time; a client's offline time is stored with its arrival time (late beyond the threshold) and is never
 *   earlier than the day's last recorded time.
 * - No automatic clock-out: a forgotten segment becomes *missing clock-out*, the next clock-in opens a new day.
 * - Idempotent per request key (unique key; a repeat answers the state). A racing second action meets the unique keys
 *   and is run again once against the new state.
 * - Every action appends a chain link in the same transaction; after the commit the task-timer link and the event.
 */
final readonly class TimeClock implements RegisterClockInterface
{
    public function __construct(
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private RegisterReader $reader,
        private ScheduleResolver $schedules,
        private RevisionChain $chain,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private ClockInterface $clock,
        private TaskTimerLinkInterface $timerLink,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private RegisterRules $rules = new RegisterRules(),
        private bool $ipEvidenceAllowed = true,
    ) {
    }

    /**
     * The worker's clock state.
     */
    public function state(?WorkerRef $worker = null): ClockState
    {
        $context = $this->context($worker);

        return $this->runner->read(fn (): ClockState => $this->resolve($context, $this->clock->now())['state']);
    }

    /**
     * Records a clock action.
     */
    public function act(?WorkerRef $worker, ClockAction $action, ?DateTimeImmutable $occurredAt, RequestKey $requestKey, SegmentSource $source, ?string $rawIp): ClockState
    {
        $context = $this->context($worker);
        $ip      = $this->ipEvidenceAllowed && $context->settings->ipEvidence() && $rawIp !== null ? IpPrefix::fromIp($rawIp) : null;
        $events  = new AfterCommitEvents();
        try {
            $state = $this->attempt($context, $action, $occurredAt, $requestKey, $source, $ip, $events);
        } catch (DuplicateRowException) {
            // Another request of the worker stored first (double click, two tabs): run once more against its state.
            $events->clear();
            try {
                $state = $this->attempt($context, $action, $occurredAt, $requestKey, $source, $ip, $events);
            } catch (DuplicateRowException) {
                throw new ClockConflictException($this->state($worker), ClockConflictException::CONCURRENT);
            }
        }
        $events->dispatch($this->dispatcher);

        return $state;
    }

    /**
     * The worker's context: a worker clocks only for themself.
     */
    private function context(?WorkerRef $worker): RegisterContext
    {
        $context = $this->access->context(WorkingTimePermission::Clock, $worker);
        if (!$context->isOwn()) {
            throw new PermissionDeniedException();
        }

        return $context;
    }

    private function attempt(RegisterContext $context, ClockAction $action, ?DateTimeImmutable $occurredAt, RequestKey $requestKey, SegmentSource $source, ?IpPrefix $ip, AfterCommitEvents $events): ClockState
    {
        $now = WorkdayRevision::utc($this->clock->now());
        $at  = $occurredAt === null ? $now : WorkdayRevision::utc($occurredAt);
        $at  = $at > $now ? $now : $at;
        /** @var array{ClockState, bool} $result */
        $result = $this->runner->write(function () use ($context, $action, $at, $now, $requestKey, $source, $ip): array {
            if ($this->store->revisionByRequestKey($context->tenant, $context->worker(), $requestKey->value) !== null) {
                return [$this->resolve($context, $now)['state'], false];
            }
            $resolved = $this->resolve($context, $at);
            if (!$action->allowedIn($resolved['state']->status)) {
                throw new ClockConflictException($this->resolve($context, $now)['state']);
            }
            $last = self::lastRecorded($resolved['state']->record);
            if ($last !== null && $at < $last) {
                throw new ClockConflictException($this->resolve($context, $now)['state'], ClockConflictException::EARLIER_THAN_LAST);
            }
            $this->record($context, $action, $at, $now, $requestKey, $source, $ip, $resolved);

            return [$this->resolve($context, $now)['state'], true];
        });
        if ($result[1]) {
            $events->add(new ClockActionRecorded($context->tenant, $context->worker(), $action, $at));
            $this->linkTimer($context, $action, $at);
        }

        return $result[0];
    }

    /**
     * @param array{state: ClockState, workday: ?Workday, stale: bool, date: LocalDate} $resolved
     */
    private function record(RegisterContext $context, ClockAction $action, DateTimeImmutable $at, DateTimeImmutable $now, RequestKey $requestKey, SegmentSource $source, ?IpPrefix $ip, array $resolved): void
    {
        $workday = $resolved['workday'];
        if ($action === ClockAction::ClockIn && ($workday === null || $resolved['stale'])) {
            $workday = $this->store->workdayOn($context->tenant, $context->worker(), $resolved['date']);
            if ($workday === null) {
                $workday = Workday::open($this->ids->generate(), $context->tenant, $context->worker(), $context->subject->displayName, $resolved['date'], $this->schedules->forDate($context->settings, $context->subject, $resolved['date']), $now);
                $this->store->addWorkday($workday);
            }
        }
        if ($workday === null) {
            // Every other action needs an open segment, so its workday exists (allowedIn() guarantees it).
            return; // @codeCoverageIgnore
        }
        $closed = null;
        $record = $resolved['stale'] ? null : $resolved['state']->record;
        $open   = $record?->openSegment();
        if ($open instanceof EffectiveSegment) {
            $segment = $this->store->segment($open->id);
            if ($segment instanceof WorkSegment && $segment->isOpen()) {
                $segment->close($at, $source, $now, $ip);
                $closed = $segment->getId();
            }
        }
        $opened = null;
        $type   = $action->opens();
        if ($type instanceof SegmentType) {
            $segment = WorkSegment::start($this->ids->generate(), $workday, $type, $at, $source, $now, $ip);
            $this->store->addSegment($segment);
            $opened = $segment->getId();
        }
        $this->runner->flush();
        $this->chain->append($workday, $action->revisionKind(), $opened ?? $closed, array_filter([
            'action'      => $action->value,
            'at'          => WorkdayRevision::iso($at),
            'received_at' => WorkdayRevision::iso($now),
            'source'      => $source->value,
            'opened'      => $opened,
            'closed'      => $closed,
            'ip_prefix'   => $ip?->value,
        ], static fn (mixed $value): bool => $value !== null), null, $context->actor, $context->subject->displayName, $now, $requestKey);
    }

    /**
     * The state at `$at`: the workday with a live open segment among the latest two, else today's.
     *
     * @return array{state: ClockState, workday: ?Workday, stale: bool, date: LocalDate}
     */
    private function resolve(RegisterContext $context, DateTimeImmutable $at): array
    {
        $records = $this->reader->records($this->store->latestWorkdays($context->tenant, $context->worker(), 2), $at);
        foreach ($records as $record) {
            $open = $record->openSegment();
            if ($open instanceof EffectiveSegment) {
                $status = $open->type === SegmentType::Break ? ClockStatus::OnBreak : ClockStatus::Working;

                return [
                    'state'   => new ClockState($status, $record->workday->date(), $record->workday->schedule()->timezone, $record, $at),
                    'workday' => $record->workday,
                    'stale'   => false,
                    'date'    => $record->workday->date(),
                ];
            }
        }
        [$date, $schedule] = $this->schedules->at($context->settings, $context->subject, $at);
        $today             = null;
        foreach ($records as $record) {
            if ($record->workday->date()->equals($date)) {
                $today = $record;
            }
        }
        $worked = false;
        foreach ($today->segments ?? [] as $segment) {
            $worked = $worked || !$segment->voided;
        }

        return [
            'state'   => new ClockState($worked ? ClockStatus::Closed : ClockStatus::Idle, $date, $schedule->timezone, $today, $at),
            'workday' => $today?->workday,
            'stale'   => $today !== null && DayRecordBuilder::staleOpen($today->workday, $today->segments, $at, $this->rules),
            'date'    => $date,
        ];
    }

    /**
     * The day's last recorded start or end, voided or stale ones included (an action is never earlier).
     */
    private static function lastRecorded(?DayRecord $record): ?DateTimeImmutable
    {
        $last = null;
        foreach ($record->segments ?? [] as $segment) {
            foreach ([$segment->start, $segment->end] as $time) {
                if ($time !== null && ($last === null || $time > $last)) {
                    $last = $time;
                }
            }
        }

        return $last;
    }

    /**
     * FR-012: clocking out or starting a break stops the running task timer; a failure never undoes the register.
     */
    private function linkTimer(RegisterContext $context, ClockAction $action, DateTimeImmutable $at): void
    {
        if (!$context->settings->taskTimerLink() || !in_array($action, [ClockAction::ClockOut, ClockAction::BreakStart], true)) {
            return;
        }
        try {
            $this->timerLink->stopRunning($context->tenant, $context->worker(), $at);
        } catch (Throwable $e) {
            $this->logger?->warning('Working-time register: the task timer could not be stopped.', ['exception' => $e::class]);
        }
    }
}
