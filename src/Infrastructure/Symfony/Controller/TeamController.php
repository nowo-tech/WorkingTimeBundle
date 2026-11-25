<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Application\Register\RegisterContext;
use Nowo\WorkingTimeBundle\Application\Register\RegisterReader;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\CorrectionRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\EffectiveSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\MonthSummary;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalTime;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\CorrectionType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\DecisionType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function count;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * The manager's register pages (contract section 11.1, `manage_team`): the team's day, a worker's date, the pending
 * correction requests with approve / reject on the version shown (a stale decision answers 409 + Retry-After with the
 * page again and a flash, nothing stored), and the manager-recorded correction. A worker the actor does not manage
 * answers 404 / 403 through the gate; nobody decides or records on their own day.
 *
 * @internal
 */
final readonly class TeamController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private RegisterAccess $access,
        private CorrectionServiceInterface $corrections,
        private RegisterReader $reader,
        private RegisterStoreInterface $store,
        private ScheduleResolver $schedules,
        private WorkerDirectoryInterface $directory,
        private TransactionRunnerInterface $runner,
        private ClockInterface $time,
        private int $pageSize = 25,
    ) {
    }

    /**
     * `GET {register}/team?date=Y-m-d`.
     */
    public function team(Request $request): Response
    {
        return $this->ui->guard(fn (): Response => $this->teamPage($request, Response::HTTP_OK));
    }

    /**
     * `GET {register}/team/day?worker=…&date=…`: a managed worker's date.
     */
    public function teamDay(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$worker, $date] = $this->target($request);

            return $this->dayPage($this->access->context(WorkingTimePermission::ManageTeam, $worker), $date);
        });
    }

    /**
     * `POST {register}/requests/{id}/{decision}` (approve | reject).
     */
    public function decide(Request $request, string $id, string $decision): Response
    {
        return $this->ui->guard(function () use ($request, $id, $decision): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context(null);
            $form = $this->ui->form(DecisionType::class, null, [], 'wt_decision_' . $id);
            $form->handleRequest($request);
            /** @var array{version: mixed, comment: mixed}|null $data */
            $data = $form->isSubmitted() && $form->isValid() ? $form->getData() : null;
            if ($data === null || !is_numeric($data['version'])) {
                throw new BadRequestHttpException('Invalid form.');
            }
            try {
                $this->corrections->decide($id, $decision === 'approve', is_string($data['comment']) ? $data['comment'] : null, (int) $data['version']);
            } catch (StaleCorrectionDecisionException $e) {
                $this->ui->flash($request, 'error', 'correction.stale');
                [$status, $headers] = $this->ui->conflict($request, $e);

                return $this->teamPage($request, $status, $headers);
            } catch (CorrectionRefusedException $e) {
                $this->ui->flash($request, 'error', $e->key);

                return $this->ui->redirect('nowo_working_time_team');
            }
            $this->ui->flash($request, 'success', 'correction.decided.' . ($decision === 'approve' ? 'approved' : 'rejected'));

            return $this->ui->redirect('nowo_working_time_team');
        });
    }

    /**
     * `POST {register}/team/record?worker=…&date=…`: a manager records a correction.
     */
    public function record(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$worker, $date] = $this->target($request);
            $context         = $this->access->context(WorkingTimePermission::ManageTeam, $worker);
            [$record, $zone] = $this->dayRecord($context, $date);
            $form            = $this->ui->form(CorrectionType::class, null, ['segments' => DayView::segmentChoices($record, $zone)]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return $this->dayPage($context, $date, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
            }
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            try {
                $this->corrections->record($worker, $date, DayView::correction($data, $date, $zone));
            } catch (CorrectionRefusedException $e) {
                $this->ui->flash($request, 'error', $e->key);

                return $this->dayPage($context, $date, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
            }
            $this->ui->flash($request, 'success', 'correction.recorded');

            return $this->ui->redirect('nowo_working_time_team_day', ['worker' => $worker->value, 'date' => $date->value]);
        });
    }

    /**
     * @param array<string, string> $headers
     */
    private function teamPage(Request $request, int $status, array $headers = []): Response
    {
        $context = $this->access->context(null);
        $date    = $this->date($request, $context);
        $workers = $this->managed($context);
        if ($workers === [] && !$this->access->can(WorkingTimePermission::ManageTeam)) {
            throw new PermissionDeniedException();
        }
        $refs = [];
        foreach ($workers as $worker) {
            $refs[] = $worker->worker;
        }
        [$days, $pending] = $this->runner->read(fn (): array => [
            $this->reader->tenant($context->tenant, $date, $date, $this->time->now(), $refs),
            $this->store->pendingCorrections($context->tenant, $refs),
        ]);
        $rows = [];
        foreach ($workers as $worker) {
            $record = $days[$worker->worker->value][$date->value] ?? null;
            $rows[] = [
                'worker' => $worker->worker->value,
                'name'   => $worker->displayName,
                'status' => $this->ui->trans('status.' . $this->status($record)),
                'worked' => MonthSummary::hours($record->workedSeconds ?? 0),
                'flags'  => $record === null ? '' : (string) count($record->flags),
            ];
        }
        $decisions = [];
        $names     = [];
        foreach ($workers as $worker) {
            $names[$worker->worker->value] = $worker->displayName;
        }
        foreach ($pending as $correction) {
            if ($correction->getWorkerId() === $context->actor->value) {
                continue;
            }
            $decisions[] = [
                'request' => $correction,
                'name'    => $names[$correction->getWorkerId()] ?? $correction->getWorkerId(),
                'form'    => $this->ui->form(DecisionType::class, ['version' => (string) $correction->getVersion()], [], 'wt_decision_' . $correction->getId())->createView(),
                'date'    => $this->runner->read(fn (): ?string => $this->store->workday($correction->getWorkdayId())?->date()->value),
                'change'  => $this->change($correction),
                'approve' => $this->ui->url('nowo_working_time_correction_decide', ['id' => $correction->getId(), 'decision' => 'approve']),
                'reject'  => $this->ui->url('nowo_working_time_correction_decide', ['id' => $correction->getId(), 'decision' => 'reject']),
            ];
        }

        return $this->ui->render('@NowoWorkingTimeBundle/register/team.html.twig', [
            'page' => new ListPage(
                new PageHeader(UiSupport::label('team.title'), UiSupport::label('team.intro', ['%date%' => $date->value]), [], tabs: $this->ui->tabs('team')),
                [
                    new Column('name', UiSupport::label('team.col.worker'), required: true),
                    new Column('status', UiSupport::label('team.col.status')),
                    new Column('worked', UiSupport::label('team.col.worked'), align: 'end'),
                    new Column('flags', UiSupport::label('team.col.flags'), align: 'end', priority: 2),
                ],
                $rows,
                rowActions: fn (mixed $row): array => is_array($row) && is_string($row['worker'] ?? null) ? [Action::view($this->ui->url('nowo_working_time_team_day', ['worker' => $row['worker'], 'date' => $date->value]), UiSupport::label('team.view_day'))] : [],
                empty: new EmptyState(UiSupport::label('team.empty')),
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'decisions' => $decisions,
        ], $status, $headers);
    }

    /**
     * @param FormInterface<mixed>|null $correction
     */
    private function dayPage(RegisterContext $context, LocalDate $date, int $status = Response::HTTP_OK, ?FormInterface $correction = null): Response
    {
        [$record, $zone] = $this->dayRecord($context, $date);
        $own             = $context->isOwn();
        $correction ??= $own ? null : $this->ui->form(CorrectionType::class, null, ['segments' => DayView::segmentChoices($record, $zone)]);

        return $this->ui->render('@NowoWorkingTimeBundle/register/team_day.html.twig', [
            'page' => new ShowPage(
                new PageHeader(UiSupport::label('team.day_title', ['%worker%' => $context->subject->displayName, '%date%' => $date->value]), null, [new Action('prev', UiSupport::label('team.back'), $this->ui->url('nowo_working_time_team', ['date' => $date->value]))], tabs: $this->ui->tabs('team')),
                DayView::sections($date, $record, $zone, $context->settings->ipEvidence()),
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'correction'        => $correction?->createView(),
            'correction_action' => $this->ui->url('nowo_working_time_correction_record', ['worker' => $context->worker()->value, 'date' => $date->value]),
        ], $status);
    }

    /**
     * @return array{?DayRecord, string}
     */
    private function dayRecord(RegisterContext $context, LocalDate $date): array
    {
        return $this->runner->read(function () use ($context, $date): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $workday = $this->store->workdayOn($context->tenant, $context->worker(), $date);
            if ($workday instanceof Workday) {
                return [$this->reader->one($workday, $this->time->now()), $workday->schedule()->timezone];
            }

            return [null, $this->schedules->forDate($context->settings, $context->subject, $date)->timezone];
        });
    }

    /**
     * The tenant's members the actor manages (the checker answers per worker), one page.
     *
     * @return list<WorkerProfile>
     */
    private function managed(RegisterContext $context): array
    {
        $managed = [];
        foreach ($this->directory->workers($context->tenant) as $worker) {
            if (count($managed) >= $this->pageSize * 4) {
                break;
            }
            try {
                $this->access->context(WorkingTimePermission::ManageTeam, $worker->worker);
                $managed[] = $worker;
            } catch (RegisterUnavailableException|PermissionDeniedException) {
                continue;
            }
        }

        return $managed;
    }

    /**
     * @return array{WorkerRef, LocalDate}
     */
    private function target(Request $request): array
    {
        $worker = $request->query->get('worker');
        $date   = $request->query->get('date');
        try {
            return [new WorkerRef(is_string($worker) ? $worker : ''), new LocalDate(is_string($date) ? $date : '')];
        } catch (InvalidArgumentException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        }
    }

    private function date(Request $request, RegisterContext $context): LocalDate
    {
        $date = $request->query->get('date');
        try {
            return is_string($date) && $date !== '' ? new LocalDate($date) : $this->schedules->at($context->settings, $context->actorProfile, $this->time->now())[0];
        } catch (InvalidArgumentException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        }
    }

    private function status(?DayRecord $record): string
    {
        $open = $record?->openSegment();
        if ($open instanceof EffectiveSegment) {
            return $open->type->value === 'break' ? 'on_break' : 'working';
        }

        return !$record instanceof DayRecord || $record->segments === [] ? 'idle' : 'closed';
    }

    private function change(CorrectionRequest $correction): string
    {
        $zone = new DateTimeZone('UTC');

        return $correction->getOperation()->value . ($correction->getNewStart() instanceof DateTimeImmutable ? ' ' . LocalTime::format($correction->getNewStart(), $zone, 'Y-m-d H:i') . ' UTC' : '') . ($correction->getNewEnd() instanceof DateTimeImmutable ? ' – ' . LocalTime::format($correction->getNewEnd(), $zone, 'Y-m-d H:i') . ' UTC' : '');
    }
}
