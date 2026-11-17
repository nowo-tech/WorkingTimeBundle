<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Exception\ChainBusyException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeOutbox;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\AfterCommitEvents;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Event\CorrectionDecided;
use Nowo\WorkingTimeBundle\Domain\Register\Event\CorrectionRecorded;
use Nowo\WorkingTimeBundle\Domain\Register\Event\CorrectionRequested;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\CorrectionRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\EffectiveSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterRules;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_filter;
use function in_array;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * Corrections — never a silent edit (spec 198 FR-014 – FR-016, contract sections 9.1 and 15.2).
 *
 * - A worker asks to correct one of their days of the last N days (setting, default 31): change a segment's start /
 *   end, add a missing segment or void one, with a reason of 10–500 characters. Nothing changes until a manager
 *   decides.
 * - A holder of `manage_team` for the worker — never the worker — approves (a chain link; the original stays) or
 *   rejects, on the version they saw: a stale version answers 409 and stores nothing, notifies nobody.
 * - A manager may record a correction (reason required): applied at once, *pending acknowledgement*; the worker
 *   acknowledges or contests with a comment, both kept in the chain.
 */
final readonly class CorrectionService implements CorrectionServiceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private RegisterReader $reader,
        private ScheduleResolver $schedules,
        private RevisionChain $chain,
        private NoticeOutbox $notices,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private WorkerDirectoryInterface $directory,
        private WorkingTimeAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
        private RegisterRules $rules = new RegisterRules(),
    ) {
    }

    /**
     * The worker's request.
     */
    public function request(LocalDate $date, CorrectionInput $input): CorrectionRequest
    {
        $context = $this->access->context(WorkingTimePermission::Clock);
        $now     = $this->clock->now();
        [$today] = $this->schedules->at($context->settings, $context->subject, $now);
        if ($date->compare($today) > 0) {
            throw new CorrectionRefusedException('correction.error.future');
        }
        if ($date->compare($today->plusDays(-$context->settings->correctionWindowDays())) < 0) {
            throw new CorrectionRefusedException('correction.error.window');
        }
        $reason  = $this->reason($input->reason);
        $events  = new AfterCommitEvents();
        $request = $this->runner->write(function () use ($context, $date, $input, $reason, $now, $events): CorrectionRequest { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $workday   = $this->workday($context, $context->worker(), $date, $now);
            $operation = $this->validate($this->reader->one($workday, $now), $input, $now);
            $request   = CorrectionRequest::file($this->ids->generate(), $workday, $operation, $operation === CorrectionOperation::Add ? null : $input->segment, $operation === CorrectionOperation::Add ? $input->segmentType : null, $input->start, $input->end, $reason, $now);
            $this->store->addCorrection($request); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            foreach ($this->directory->workers($context->tenant) as $manager) {
                if (!$manager->worker->isActor($context->actor) && $this->managesAs($context->tenant, $manager->worker, $context->worker())) {
                    $this->notices->queue($context->settings, $manager->worker, RegisterNotice::CORRECTION_REQUESTED, ['worker' => $context->subject->displayName, 'date' => $date->value], null, 'request:' . $request->getId() . ':' . $manager->worker->value, $now);
                }
            }
            $this->audit->record(new AuditEntry('working_time.correction_requested', $context->tenant, $context->actor, $context->worker(), ['request' => $request->getId(), 'date' => $date->value, 'operation' => $operation->value]));
            $events->clear();
            $events->add(new CorrectionRequested($context->tenant, $context->worker(), $request->getId()));

            return $request;
        });
        $events->dispatch($this->dispatcher);

        return $request;
    }

    /**
     * A manager's decision on the version they saw.
     */
    public function decide(string $correctionId, bool $approve, ?string $comment, int $expectedVersion): CorrectionRequest
    {
        $tenant   = $this->access->context(null)->tenant;
        $existing = $this->runner->read(fn (): ?CorrectionRequest => $this->store->correction($correctionId));
        if ($existing === null || $existing->getTenantId() !== $tenant->value) {
            throw new RegisterUnavailableException();
        }
        $context = $this->access->context(WorkingTimePermission::ManageTeam, new WorkerRef($existing->getWorkerId()));
        if ($context->isOwn()) {
            throw new CorrectionRefusedException('correction.error.own_day');
        }
        $comment = $this->comment($comment);
        $events  = new AfterCommitEvents();
        $request = $this->raced(fn (): CorrectionRequest => $this->runner->write(function () use ($context, $correctionId, $approve, $comment, $expectedVersion, $events): CorrectionRequest { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $now     = $this->clock->now();
            $request = $this->store->correction($correctionId) ?? throw new RegisterUnavailableException();
            if ($request->getVersion() !== $expectedVersion) {
                throw new StaleCorrectionDecisionException();
            }
            if (!$request->isPending()) {
                throw new CorrectionRefusedException('correction.error.already_decided');
            }
            $workday = $this->store->workday($request->getWorkdayId()) ?? throw new RegisterUnavailableException();
            $name    = $context->actorProfile->displayName;
            if ($approve) {
                $segment  = $request->getSegmentId() ?? $this->ids->generate();
                $revision = $this->chain->append($workday, RevisionKind::Correction, $segment, $this->payload($request->getOperation(), $segment, $request->getSegmentType()?->value, $request->getNewStart(), $request->getNewEnd(), [ // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                    'request'        => $request->getId(),
                    'approved_by_id' => $context->actor->value,
                    'approved_by'    => $name,
                    'comment'        => $comment,
                ]), $request->getReason(), $context->actor, $name, $now);
                $request->approve($context->actor, $name, $comment, $revision->getId(), $now);
            } else {
                $request->reject($context->actor, $name, $comment, $now);
            }
            $this->runner->flush();
            $this->notices->queue($context->settings, $context->worker(), RegisterNotice::CORRECTION_DECIDED, ['date' => $workday->date()->value, 'decision' => $approve ? 'approved' : 'rejected', 'actor' => $name], null, 'decided:' . $request->getId(), $now);
            $this->audit->record(new AuditEntry($approve ? 'working_time.correction_approved' : 'working_time.correction_rejected', $context->tenant, $context->actor, $context->worker(), ['request' => $request->getId(), 'date' => $workday->date()->value]));
            $events->clear();
            $events->add(new CorrectionDecided($context->tenant, $context->worker(), $request->getId(), $approve));

            return $request;
        }), static fn (Throwable $race): Throwable => new StaleCorrectionDecisionException($race));
        $events->dispatch($this->dispatcher);

        return $request;
    }

    /**
     * A manager records a correction on a worker's day.
     */
    public function record(WorkerRef $worker, LocalDate $date, CorrectionInput $input): WorkdayRevision
    {
        $context = $this->access->context(WorkingTimePermission::ManageTeam, $worker);
        if ($context->isOwn()) {
            throw new CorrectionRefusedException('correction.error.own_day');
        }
        $now     = $this->clock->now();
        [$today] = $this->schedules->at($context->settings, $context->subject, $now);
        if ($date->compare($today) > 0) {
            throw new CorrectionRefusedException('correction.error.future');
        }
        $reason   = $this->reason($input->reason);
        $events   = new AfterCommitEvents();
        $revision = $this->raced(fn (): WorkdayRevision => $this->runner->write(function () use ($context, $date, $input, $reason, $now, $events): WorkdayRevision { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $workday   = $this->workday($context, $context->worker(), $date, $now);
            $operation = $this->validate($this->reader->one($workday, $now), $input, $now);
            $segment   = $operation === CorrectionOperation::Add ? $this->ids->generate() : (string) $input->segment;
            $name      = $context->actorProfile->displayName;
            $revision  = $this->chain->append($workday, RevisionKind::RecordedCorrection, $segment, $this->payload($operation, $segment, $operation === CorrectionOperation::Add ? $input->segmentType?->value : null, $input->start, $input->end, [ // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                'recorded_by_id' => $context->actor->value,
                'recorded_by'    => $name,
            ]), $reason, $context->actor, $name, $now);
            $this->notices->queue($context->settings, $context->worker(), RegisterNotice::CORRECTION_RECORDED, ['date' => $date->value, 'actor' => $name], null, 'recorded:' . $revision->getId(), $now);
            $this->audit->record(new AuditEntry('working_time.correction_recorded', $context->tenant, $context->actor, $context->worker(), ['revision' => $revision->getId(), 'date' => $date->value, 'operation' => $operation->value]));
            $events->clear();
            $events->add(new CorrectionRecorded($context->tenant, $context->worker(), $revision->getId()));

            return $revision;
        }), static fn (Throwable $race): Throwable => new ChainBusyException($race));
        $events->dispatch($this->dispatcher);

        return $revision;
    }

    /**
     * The worker's answer to a recorded correction.
     */
    public function answer(string $recordedRevisionId, bool $contest, ?string $comment): WorkdayRevision
    {
        $context = $this->access->context(WorkingTimePermission::Clock);
        $comment = $this->comment($comment);
        if ($contest && ($comment === null || mb_strlen($comment) < CorrectionRequest::REASON_MIN)) {
            throw new CorrectionRefusedException('correction.error.reason');
        }

        return $this->raced(fn (): WorkdayRevision => $this->runner->write(function () use ($context, $recordedRevisionId, $contest, $comment): WorkdayRevision { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $now      = $this->clock->now();
            $recorded = $this->store->revision($recordedRevisionId);
            $workday  = $recorded instanceof WorkdayRevision ? $this->store->workday($recorded->getWorkdayId()) : null;
            if (!$recorded instanceof WorkdayRevision || !$workday instanceof Workday || $recorded->getKind() !== RevisionKind::RecordedCorrection || $workday->getWorkerId() !== $context->worker()->value || $workday->getTenantId() !== $context->tenant->value) {
                throw new RegisterUnavailableException();
            }
            if (!in_array($recorded->getId(), $this->reader->one($workday, $now)->pendingAcknowledgements, true)) {
                throw new CorrectionRefusedException('correction.error.already_answered');
            }
            $revision = $this->chain->append($workday, $contest ? RevisionKind::Contested : RevisionKind::Acknowledged, $recorded->getSegmentId(), ['revision' => $recorded->getId()], $comment, $context->actor, $context->subject->displayName, $now); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->audit->record(new AuditEntry($contest ? 'working_time.correction_contested' : 'working_time.correction_acknowledged', $context->tenant, $context->actor, $context->worker(), ['revision' => $recorded->getId(), 'date' => $workday->date()->value]));

            return $revision;
        }), static fn (Throwable $race): Throwable => new ChainBusyException($race));
    }

    /**
     * Runs a chain-appending write; when another writer appended first (the unique seq refused the link, or a version
     * moved under the flush) the write runs once more against the new head and version; a second race is the given
     * conflict (409 + Retry-After). Nothing of a refused attempt is stored (audit ST-M2).
     *
     * @template T
     *
     * @param callable(): T $write
     * @param callable(Throwable): Throwable $conflict
     *
     * @return T
     */
    private function raced(callable $write, callable $conflict): mixed
    {
        try {
            return $write();
        } catch (DuplicateRowException) {
            try {
                return $write();
            } catch (DuplicateRowException|StaleRowException $again) {
                throw $conflict($again);
            }
        } catch (StaleRowException $stale) {
            throw $conflict($stale);
        }
    }

    /**
     * Whether `$manager` holds manage_team for `$worker` — asked of the checker as that manager is not the current
     * actor: the directory's managedBy() rule decides who is notified.
     */
    private function managesAs(TenantRef $tenant, WorkerRef $manager, WorkerRef $worker): bool
    {
        foreach ($this->directory->managedBy($tenant, new ActorRef($manager->value)) as $managed) {
            if ($managed->equals($worker)) {
                return true;
            }
        }

        return false;
    }

    private function workday(RegisterContext $context, WorkerRef $worker, LocalDate $date, DateTimeImmutable $now): Workday
    {
        $workday = $this->store->workdayOn($context->tenant, $worker, $date);
        if ($workday instanceof Workday) {
            return $workday;
        }
        $workday = Workday::open($this->ids->generate(), $context->tenant, $worker, $context->subject->displayName, $date, $this->schedules->forDate($context->settings, $context->subject, $date), $now);
        $this->store->addWorkday($workday); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->runner->flush();

        return $workday;
    }

    /**
     * The change must make sense on the day as it stands: the segment exists (and is not live), start before end, no
     * time in the future, at most the long-day hours.
     */
    private function validate(DayRecord $record, CorrectionInput $input, DateTimeImmutable $now): CorrectionOperation
    {
        $operation = $input->operation;
        if ($operation !== CorrectionOperation::Add) {
            $segment = null;
            foreach ($record->segments as $candidate) {
                if ($candidate->id === $input->segment && !$candidate->voided) {
                    $segment = $candidate;
                }
            }
            if (!$segment instanceof EffectiveSegment) {
                throw new CorrectionRefusedException('correction.error.segment');
            }
            if ($segment === $record->openSegment()) {
                throw new CorrectionRefusedException('correction.error.live_segment');
            }
            if ($operation === CorrectionOperation::Void) {
                return $operation;
            }
            if (!$input->start instanceof DateTimeImmutable && !$input->end instanceof DateTimeImmutable) {
                throw new CorrectionRefusedException('correction.error.times');
            }
            $start = $input->start ?? $segment->start;
            $end   = $input->end ?? $segment->end;
        } else {
            if (!$input->start instanceof DateTimeImmutable || !$input->end instanceof DateTimeImmutable || !$input->segmentType instanceof SegmentType) {
                throw new CorrectionRefusedException('correction.error.times');
            }
            $start = $input->start;
            $end   = $input->end;
        }
        if ($start > $now || ($end instanceof DateTimeImmutable && ($end <= $start || $end > $now || $end->getTimestamp() - $start->getTimestamp() > $this->rules->longDaySeconds))) {
            throw new CorrectionRefusedException('correction.error.times');
        }

        return $operation;
    }

    /**
     * @param array<string, scalar|null> $extra
     *
     * @return array<string, scalar>
     */
    private function payload(CorrectionOperation $operation, string $segment, ?string $type, ?DateTimeImmutable $start, ?DateTimeImmutable $end, array $extra): array
    {
        $void = $operation === CorrectionOperation::Void;

        return array_filter([
            'op'      => $operation->value,
            'segment' => $segment,
            'type'    => $type,
            'start'   => !$start instanceof DateTimeImmutable || $void ? null : WorkdayRevision::iso($start),
            'end'     => !$end instanceof DateTimeImmutable || $void ? null : WorkdayRevision::iso($end),
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        $length = mb_strlen($reason);
        if ($length < CorrectionRequest::REASON_MIN || $length > CorrectionRequest::REASON_MAX) {
            throw new CorrectionRefusedException('correction.error.reason');
        }

        return $reason;
    }

    private function comment(?string $comment): ?string
    {
        $comment = $comment === null ? null : mb_substr(trim($comment), 0, CorrectionRequest::REASON_MAX);

        return $comment === '' ? null : $comment;
    }
}
