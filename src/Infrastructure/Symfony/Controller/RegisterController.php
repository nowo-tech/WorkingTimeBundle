<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use DateTimeZone;
use InvalidArgumentException;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\KpiTile;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAnnotations;
use Nowo\WorkingTimeBundle\Application\Register\RegisterContext;
use Nowo\WorkingTimeBundle\Application\Register\RegisterReader;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ClockConflictException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\CorrectionRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\EffectiveSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\MonthSummary;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\RegisterCsv;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\AnswerType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ClockType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\CorrectionType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function ksort;

/**
 * The worker's own register (contract section 11.1): today with the clock buttons, a month, a day (by id or date),
 * clocking, correction requests and the answer to a manager's recorded correction. Every page asks the gate (`clock`,
 * own register); a day of another worker opens only for a holder of `manage_team` for them. Every POST is a FormKit
 * form with its own CSRF token; the clock buttons carry an idempotency key (a double click acts once).
 *
 * @internal
 */
final readonly class RegisterController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private RegisterAccess $access,
        private RegisterClockInterface $clock,
        private CorrectionServiceInterface $corrections,
        private RegisterReader $reader,
        private RegisterStoreInterface $store,
        private ScheduleResolver $schedules,
        private TransactionRunnerInterface $runner,
        private ClockInterface $time,
        private RegisterAnnotations $annotations,
    ) {
    }

    /**
     * `GET {register}`: today.
     */
    public function today(): Response
    {
        return $this->ui->guard(fn (): Response => $this->todayPage($this->access->context(WorkingTimePermission::Clock), $this->clock->state()));
    }

    /**
     * `POST {register}/clock/{action}`.
     */
    public function clock(Request $request, string $action): Response
    {
        return $this->ui->guard(function () use ($request, $action): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $clockAction = ClockAction::tryFrom($action) ?? throw new NotFoundHttpException();
            $context     = $this->access->context(WorkingTimePermission::Clock);
            $form        = $this->ui->form(ClockType::class, null, [], 'wt_clock_' . $clockAction->key());
            $form->handleRequest($request);
            $key = $form->isSubmitted() && $form->isValid() ? $form->get('requestKey')->getData() : null;
            if (!is_string($key)) {
                throw new BadRequestHttpException('Invalid form.');
            }
            try {
                $this->clock->act(null, $clockAction, null, RequestKey::web($key), SegmentSource::Web, $request->getClientIp());
            } catch (ClockConflictException $e) {
                $this->ui->flash($request, 'error', 'clock.conflict.' . $e->reason);

                return $this->todayPage($context, $e->state, Response::HTTP_CONFLICT);
            } catch (InvalidArgumentException) {
                throw new BadRequestHttpException('Invalid request key.');
            }
            $this->ui->flash($request, 'success', 'clock.done.' . $clockAction->key());

            return $this->ui->redirect('nowo_working_time_today');
        });
    }

    /**
     * `GET {register}/month` (current) and `GET {register}/month/{month}`.
     */
    public function month(Request $request, ?string $month = null): Response
    {
        return $this->ui->guard(function () use ($month): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context = $this->access->context(WorkingTimePermission::Clock);
            [$today] = $this->schedules->at($context->settings, $context->subject, $this->time->now());
            $month ??= $today->month();
            try {
                $first = new LocalDate($month . '-01');
            } catch (InvalidArgumentException $e) {
                throw new NotFoundHttpException('Not found.', $e);
            }
            $last  = LocalDate::fromDateTime($first->toDateTime()->modify('last day of this month'));
            $days  = $this->runner->read(fn (): array => $this->reader->worker($context->tenant, $context->worker(), $first, $last, $this->time->now()));
            $notes = $this->notes($context, $first, $last);
            $rows  = [];
            foreach ($days as $date => $record) {
                $zone        = $record->workday->schedule()->zone();
                $rows[$date] = [
                    'date'     => (string) $date,
                    'start'    => RegisterCsv::local($record->firstStart(), $zone),
                    'end'      => RegisterCsv::local($record->lastEnd(), $zone),
                    'worked'   => MonthSummary::hours($record->workedSeconds),
                    'expected' => MonthSummary::hours($record->workday->schedule()->expectedSeconds),
                    'flags'    => $record->flagValues() === [] ? '' : (string) count($record->flags),
                    'note'     => $notes[$date] ?? '',
                ];
            }
            foreach ($notes as $date => $note) {
                $rows[$date] ??= ['date' => (string) $date, 'start' => '', 'end' => '', 'worked' => '', 'expected' => '', 'flags' => '', 'note' => $note];
            }
            ksort($rows);
            $rows     = array_values($rows);
            $summary  = MonthSummary::of($days);
            $previous = $first->toDateTime()->modify('-1 month')->format('Y-m');
            $next     = $first->toDateTime()->modify('+1 month')->format('Y-m');
            $actions  = [new Action('prev', UiSupport::label('month.previous'), $this->ui->url('nowo_working_time_month', ['month' => $previous]))];
            if ($next <= $today->month()) {
                $actions[] = new Action('next', UiSupport::label('month.next'), $this->ui->url('nowo_working_time_month', ['month' => $next]));
            }

            return $this->ui->render('@NowoWorkingTime/register/month.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('month.title', ['%month%' => $month]), UiSupport::label('month.intro', ['%worked%' => MonthSummary::hours($summary->workedSeconds), '%expected%' => MonthSummary::hours($summary->expectedSeconds), '%days%' => $summary->daysWorked]), $actions, tabs: $this->ui->tabs('month')),
                    [
                        new Column('date', UiSupport::label('month.col.date'), required: true),
                        new Column('start', UiSupport::label('month.col.start')),
                        new Column('end', UiSupport::label('month.col.end')),
                        new Column('worked', UiSupport::label('month.col.worked'), align: 'end'),
                        new Column('expected', UiSupport::label('month.col.expected'), align: 'end', priority: 2),
                        new Column('flags', UiSupport::label('month.col.flags'), align: 'end', priority: 3),
                        new Column('note', UiSupport::label('month.col.note'), priority: 2),
                    ],
                    $rows,
                    rowActions: fn (mixed $row): array => is_array($row) && is_string($row['date'] ?? null) ? [Action::view($this->ui->url('nowo_working_time_date', ['date' => $row['date']]), UiSupport::label('month.view_day'))] : [],
                    empty: new EmptyState(UiSupport::label('month.empty')),
                    bundle: 'NowoWorkingTimeBundle',
                    layout: $this->ui->layout(),
                ),
            ]);
        });
    }

    /**
     * `GET {register}/day/{id}`: one workday (own, or of a managed worker).
     */
    public function day(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $member  = $this->access->context(null);
            $workday = $this->runner->read(fn (): ?Workday => $this->store->workday($id));
            if ($workday === null || $workday->getTenantId() !== $member->tenant->value) {
                throw new RegisterUnavailableException();
            }
            $worker = new WorkerRef($workday->getWorkerId());
            if (!$worker->isActor($member->actor)) {
                return $this->ui->redirect('nowo_working_time_team_day', ['worker' => $worker->value, 'date' => $workday->date()->value]);
            }

            return $this->dayPage($this->access->context(WorkingTimePermission::Clock), $workday->date());
        });
    }

    /**
     * `GET {register}/date/{date}`: the worker's own date (an empty day can get a correction that adds a segment).
     */
    public function date(Request $request, string $date): Response
    {
        return $this->ui->guard(fn (): Response => $this->dayPage($this->access->context(WorkingTimePermission::Clock), $this->localDate($date)));
    }

    /**
     * `POST {register}/date/{date}/correction`: the worker's correction request.
     */
    public function requestCorrection(Request $request, string $date): Response
    {
        return $this->ui->guard(function () use ($request, $date): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context         = $this->access->context(WorkingTimePermission::Clock);
            $day             = $this->localDate($date);
            [$record, $zone] = $this->record($context, $day);
            $form            = $this->ui->form(CorrectionType::class, null, ['segments' => DayView::segmentChoices($record, $zone)]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return $this->dayPage($context, $day, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
            }
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            try {
                $this->corrections->request($day, DayView::correction($data, $day, $zone));
            } catch (CorrectionRefusedException $e) {
                $this->ui->flash($request, 'error', $e->key);

                return $this->dayPage($context, $day, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
            }
            $this->ui->flash($request, 'success', 'correction.requested');

            return $this->ui->redirect('nowo_working_time_date', ['date' => $day->value]);
        });
    }

    /**
     * `POST {register}/corrections/{id}/answer`: acknowledge or contest a recorded correction.
     */
    public function answer(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context = $this->access->context(WorkingTimePermission::Clock);
            $form    = $this->ui->form(AnswerType::class, null, [], 'wt_answer_' . $id);
            $form->handleRequest($request);
            $revision = $this->runner->read(fn (): ?\Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision => $this->store->revision($id));
            $workday  = $revision === null ? null : $this->runner->read(fn (): ?Workday => $this->store->workday($revision->getWorkdayId()));
            if ($workday === null || $workday->getTenantId() !== $context->tenant->value || $workday->getWorkerId() !== $context->worker()->value) {
                throw new RegisterUnavailableException();
            }
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new BadRequestHttpException('Invalid form.');
            }
            /** @var array{answer: mixed, comment: mixed} $data */
            $data = $form->getData();
            try {
                $this->corrections->answer($id, $data['answer'] === 'contest', is_string($data['comment']) ? $data['comment'] : null);
                $this->ui->flash($request, 'success', 'correction.answered');
            } catch (CorrectionRefusedException $e) {
                $this->ui->flash($request, 'error', $e->key);
            }

            return $this->ui->redirect('nowo_working_time_date', ['date' => $workday->date()->value]);
        });
    }

    /**
     * @param FormInterface<mixed>|null $correction a submitted form to show again
     */
    private function dayPage(RegisterContext $context, LocalDate $date, int $status = Response::HTTP_OK, ?FormInterface $correction = null): Response
    {
        [$record, $zone] = $this->record($context, $date);
        $correction ??= $this->ui->form(CorrectionType::class, null, ['segments' => DayView::segmentChoices($record, $zone)]);
        $answers = [];
        foreach ($record->pendingAcknowledgements ?? [] as $revisionId) {
            $answers[] = ['form' => $this->ui->form(AnswerType::class, null, [], 'wt_answer_' . $revisionId)->createView(), 'action' => $this->ui->url('nowo_working_time_correction_answer', ['id' => $revisionId])];
        }

        return $this->ui->render('@NowoWorkingTime/register/day.html.twig', [
            'page' => new ShowPage(
                new PageHeader(UiSupport::label('day.title', ['%date%' => $date->value]), null, [new Action('prev', UiSupport::label('day.back_to_month'), $this->ui->url('nowo_working_time_month', ['month' => $date->month()]))], tabs: $this->ui->tabs('month')),
                DayView::sections($date, $record, $zone, $context->settings->ipEvidence()),
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'correction'        => $correction->createView(),
            'correction_action' => $this->ui->url('nowo_working_time_correction_request', ['date' => $date->value]),
            'answers'           => $answers,
        ], $status);
    }

    /**
     * @return array{?DayRecord, string}
     */
    private function record(RegisterContext $context, LocalDate $date): array
    {
        return $this->runner->read(function () use ($context, $date): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $workday = $this->store->workdayOn($context->tenant, $context->worker(), $date);
            if ($workday instanceof Workday) {
                return [$this->reader->one($workday, $this->time->now()), $workday->schedule()->timezone];
            }

            return [null, $this->schedules->forDate($context->settings, $context->subject, $date)->timezone];
        });
    }

    private function todayPage(RegisterContext $context, ClockState $state, int $status = Response::HTTP_OK): Response
    {
        $forms = [];
        foreach ($state->allowedActions() as $action) {
            $forms[$action->value] = $this->ui->form(ClockType::class, ['requestKey' => UiSupport::requestKey()], ['action' => $this->ui->url('nowo_working_time_clock', ['action' => $action->value])], 'wt_clock_' . $action->key())->createView();
        }
        $zone     = new DateTimeZone($state->timezone);
        $segments = array_map(static fn (EffectiveSegment $segment): array => [
            'type'  => $segment->type->value,
            'start' => RegisterCsv::local($segment->start, $zone),
            'end'   => RegisterCsv::local($segment->end, $zone),
        ], $state->record->segments ?? []);

        return $this->ui->render('@NowoWorkingTime/register/today.html.twig', [
            'page' => new DashboardPage(
                new PageHeader(UiSupport::label('today.title'), UiSupport::label('today.intro', ['%date%' => $state->date->value]), [new Action('view', UiSupport::label('today.view_day'), $this->ui->url('nowo_working_time_date', ['date' => $state->date->value]))], tabs: $this->ui->tabs('today')),
                [
                    new KpiTile(UiSupport::label('today.status'), $this->status($state), variant: $state->status->value === 'working' ? 'success' : 'neutral'),
                    new KpiTile(UiSupport::label('today.worked'), MonthSummary::hours($state->workedSeconds())),
                    new KpiTile(UiSupport::label('today.breaks'), MonthSummary::hours($state->breakSeconds())),
                ],
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'state'     => $state,
            'forms'     => $forms,
            'segments'  => $segments,
            'ip_notice' => $context->settings->ipEvidence() ? $context->settings->ipNoticeFor($context->locale) : null,
        ], $status);
    }

    private function status(ClockState $state): string
    {
        return $this->ui->trans('status.' . $state->status->value);
    }

    private function localDate(string $date): LocalDate
    {
        try {
            return new LocalDate($date);
        } catch (InvalidArgumentException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        }
    }

    /**
     * The labels of the month's annotated days (holidays; leave from S3), `Y-m-d` => text.
     *
     * @return array<string, string>
     */
    private function notes(RegisterContext $context, LocalDate $first, LocalDate $last): array
    {
        $annotations = $this->annotations;
        $notes       = [];
        foreach ($this->runner->read(static fn (): array => $annotations->between($context->settings, $context->subject, $first, $last)) as $date => $dayAnnotations) { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $labels = [];
            foreach ($dayAnnotations as $annotation) {
                $labels[] = $annotation->label;
            }
            $notes[(string) $date] = implode(' · ', $labels);
        }

        return $notes;
    }
}
