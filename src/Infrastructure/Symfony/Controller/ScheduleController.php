<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use InvalidArgumentException;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ScheduleRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ScheduleType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\WithdrawType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function array_pad;
use function array_search;
use function explode;
use function implode;
use function is_int;
use function is_string;
use function preg_split;
use function sprintf;
use function trim;

/**
 * Dated schedules (contract section 11.1, `manage_team`): the tenant's rows, a form to add one (tenant default, a
 * group, a worker; valid from today or later) and the withdrawal of a row that is not valid yet.
 *
 * @internal
 */
final readonly class ScheduleController
{
    /** Weekday keys of the form. */
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private RegisterAccess $access,
        private ScheduleServiceInterface $schedules,
        private WorkerDirectoryInterface $directory,
        private ClockInterface $clock,
        private string $defaultTimezone = 'UTC',
        private ?HolidayCalendarReaderInterface $holidays = null,
    ) {
    }

    /**
     * `GET|POST {register}/schedules`.
     */
    public function schedules(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->form();
            $form->handleRequest($request);
            if (!$form->isSubmitted()) {
                return $this->page($form, Response::HTTP_OK);
            }
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                try {
                    [$scope, $target, $from, $definition] = $this->input($data);
                    $this->schedules->assign($scope, $target, $from, $definition, is_string($data['workCentre'] ?? null) && $data['workCentre'] !== '' ? $data['workCentre'] : null);
                    $this->ui->flash($request, 'success', 'schedule.saved');

                    return $this->ui->redirect('nowo_working_time_schedules');
                } catch (ScheduleRefusedException $e) {
                    $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                } catch (InvalidArgumentException) {
                    $form->addError(new FormError($this->ui->trans('schedule.error.invalid'))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
            }

            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * `POST {register}/schedules/{id}/withdraw`.
     */
    public function withdraw(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context(WorkingTimePermission::ManageTeam);
            $form = $this->ui->form(WithdrawType::class, null, [], 'wt_withdraw_' . $id);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new BadRequestHttpException('Invalid form.');
            }
            try {
                $this->schedules->withdrawFuture($id);
                $this->ui->flash($request, 'success', 'schedule.withdrawn');
            } catch (ScheduleRefusedException $e) {
                $this->ui->flash($request, 'error', $e->key);
            }

            return $this->ui->redirect('nowo_working_time_schedules');
        });
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function page(FormInterface $form, int $status): Response
    {
        $rows      = [];
        $withdraws = [];
        $today     = $this->clock->now()->format('Y-m-d');
        foreach ($this->schedules->list() as $row) {
            $rows[] = $this->row($row);
            if ($row->validFrom()->value > $today) {
                $withdraws[] = ['form' => $this->ui->form(WithdrawType::class, null, [], 'wt_withdraw_' . $row->getId())->createView(), 'action' => $this->ui->url('nowo_working_time_schedule_withdraw', ['id' => $row->getId()]), 'valid_from' => $row->validFrom()->value];
            }
        }

        return $this->ui->render('@NowoWorkingTime/register/schedules.html.twig', [
            'page' => new ListPage(
                new PageHeader(UiSupport::label('schedule.title'), UiSupport::label('schedule.intro'), [], tabs: $this->ui->tabs('schedules')),
                [
                    new Column('valid_from', UiSupport::label('schedule.col.valid_from'), required: true),
                    new Column('scope', UiSupport::label('schedule.col.scope')),
                    new Column('target', UiSupport::label('schedule.col.target')),
                    new Column('timezone', UiSupport::label('schedule.col.timezone'), priority: 3),
                    new Column('slots', UiSupport::label('schedule.col.slots'), priority: 2),
                ],
                $rows,
                empty: new EmptyState(UiSupport::label('schedule.empty')),
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'form'      => $form->createView(),
            'withdraws' => $withdraws,
        ], $status);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(): FormInterface
    {
        $context = $this->access->context(WorkingTimePermission::ManageTeam);
        $groups  = [];
        foreach ($this->directory->groups($context->tenant) as [$group, $label]) {
            $groups[$label] = $group->value;
        }
        $workers = [];
        foreach ($this->directory->workers($context->tenant) as $worker) {
            $workers[$worker->displayName] = $worker->worker->value;
        }

        $centres = [];
        foreach ($this->holidays?->workCentres($context->tenant) ?? [] as $centre) {
            $centres[$centre->getName()] = $centre->getId();
        }

        return $this->ui->form(ScheduleType::class, ['timezone' => $this->defaultTimezone, 'scope' => 'tenant'], ['groups' => $groups, 'workers' => $workers, 'work_centres' => $centres]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{ScheduleScope, ?string, LocalDate, ScheduleDefinition}
     */
    private function input(array $data): array
    {
        $scope  = ScheduleScope::from(is_string($data['scope'] ?? null) ? $data['scope'] : 'tenant');
        $target = match ($scope) {
            ScheduleScope::Group  => is_string($data['group'] ?? null) ? $data['group'] : throw new ScheduleRefusedException('schedule.error.group'),
            ScheduleScope::Worker => is_string($data['worker'] ?? null) ? $data['worker'] : throw new ScheduleRefusedException('schedule.error.worker'),
            ScheduleScope::Tenant => null,
        };
        $slots = [];
        foreach (self::DAYS as $key => $day) {
            $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
            foreach ($value === '' ? [] : (preg_split('/\s*,\s*/', $value) ?: []) as $range) {
                [$from, $to] = array_pad(explode('-', trim($range), 2), 2, '');
                $slots[]     = ['day' => $day, 'from' => trim($from), 'to' => trim($to)];
            }
        }

        return [
            $scope,
            $target,
            new LocalDate(is_string($data['validFrom'] ?? null) ? $data['validFrom'] : ''),
            new ScheduleDefinition(
                is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC',
                $slots,
                is_int($data['dailyTarget'] ?? null) ? $data['dailyTarget'] : null,
                is_int($data['weeklyTarget'] ?? null) ? $data['weeklyTarget'] : null,
                is_int($data['flex'] ?? null) ? $data['flex'] : 0,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(ScheduleAssignment $row): array
    {
        $definition = $row->definition();
        $slots      = [];
        foreach ($definition->slots as $slot) {
            $slots[] = sprintf('%s %s–%s', (string) array_search($slot['day'], self::DAYS, true), $slot['from'], $slot['to']);
        }
        if ($definition->dailyTargetMinutes !== null) {
            $slots[] = sprintf('%d min/day', $definition->dailyTargetMinutes);
        }

        return [
            'id'         => $row->getId(),
            'valid_from' => $row->validFrom()->value,
            'scope'      => $row->getScope()->value,
            'target'     => $row->getTargetId() ?? '',
            'timezone'   => $definition->timezone,
            'slots'      => implode(', ', $slots),
        ];
    }
}
