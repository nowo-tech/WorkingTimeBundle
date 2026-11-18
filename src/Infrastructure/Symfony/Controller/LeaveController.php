<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\WorkingTimeBundle\Application\Leave\LeaveAccess;
use Nowo\WorkingTimeBundle\Application\Leave\StaleLeaveSettingsException;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\RoundingMode;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveAdministrationInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveBalanceReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\AllowanceLineType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\AllowancePolicyType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\LeaveSettingsType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\LeaveTypeType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * The leave foundation pages (contract section 11.1): the worker's balances (`leave_request`), the types and the
 * allowances (`leave_manage`: types with seeding and archiving, policies, adjustment and carry-over lines with a
 * worker's balance preview) and the leave settings next to the register's (`manage_settings`). The request workflow
 * arrives with S3.
 *
 * @internal
 */
final readonly class LeaveController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private LeaveAccess $access,
        private LeaveAdministrationInterface $administration,
        private LeaveBalanceReaderInterface $balances,
        private WorkerDirectoryInterface $directory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `GET {leave}?year=`: the actor's balances.
     */
    public function index(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context = $this->access->context(WorkingTimePermission::LeaveRequest);
            $year    = $this->year($request);

            return $this->balancePage($context->tenant, $context->actor->asWorker(), $year, $context->locale, 'balances', UiSupport::label('leave.balance.title', ['%year%' => $year]));
        });
    }

    /**
     * `GET|POST {leave}/manage`: the types; the POST adds one.
     */
    public function types(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context = $this->access->context(WorkingTimePermission::LeaveManage);
            $form    = $this->typeForm(null);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid() && $this->saveType($form, null) instanceof LeaveType) {
                $this->ui->flash($request, 'success', 'leave.flash.type_saved');

                return $this->ui->redirect('nowo_working_time_leave_manage');
            }
            $rows = [];
            foreach ($this->administration->types() as $type) {
                $rows[] = [
                    'id'     => $type->getId(),
                    'name'   => $type->nameIn($context->locale),
                    'unit'   => $this->ui->trans($type->getUnit()->labelKey()),
                    'source' => $this->ui->trans($type->getSource()->labelKey()),
                    'state'  => $this->ui->trans($type->isArchived() ? 'leave.type.archived' : ($type->isMedical() ? 'leave.type.medical' : 'leave.type.active')),
                ];
            }

            return $this->ui->render('@NowoWorkingTime/leave/types.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('leave.type.title'), UiSupport::label('leave.type.intro'), [], tabs: $this->tabs('types')),
                    [
                        new Column('name', UiSupport::label('leave.type.col.name'), required: true),
                        new Column('unit', UiSupport::label('leave.type.col.unit')),
                        new Column('source', UiSupport::label('leave.type.col.source'), priority: 2),
                        new Column('state', UiSupport::label('leave.type.col.state'), priority: 3),
                    ],
                    $rows,
                    rowActions: fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::edit($this->ui->url('nowo_working_time_leave_type', ['id' => $row['id']]), UiSupport::label('leave.type.edit'))] : [],
                    empty: new EmptyState(UiSupport::label('leave.type.empty')),
                    bundle: 'NowoWorkingTimeBundle',
                    layout: $this->ui->layout(),
                ),
                'form' => $form->createView(),
                'seed' => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_leave_seed'], 'wt_leave_seed')->createView(),
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `POST {leave}/manage/seed`: seeds the missing default types.
     */
    public function seed(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, WorkingTimePermission::LeaveManage, 'wt_leave_seed', 'nowo_working_time_leave_seed');
            $this->ui->flashParams($request, 'success', 'leave.flash.seeded', ['%count%' => $this->administration->seedTypes()]);

            return $this->ui->redirect('nowo_working_time_leave_manage');
        });
    }

    /**
     * `GET|POST {leave}/manage/types/{id}`: one type.
     */
    public function type(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context = $this->access->context(WorkingTimePermission::LeaveManage);
            $type    = $this->administration->type($id);
            $form    = $this->typeForm($type->input($context->locale), $type->getVersion());
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid() && $this->saveType($form, $id) instanceof LeaveType) {
                $this->ui->flash($request, 'success', 'leave.flash.type_saved');

                return $this->ui->redirect('nowo_working_time_leave_manage');
            }

            return $this->ui->render('@NowoWorkingTime/leave/type.html.twig', [
                'page'     => new ListPage(new PageHeader(UiSupport::label('leave.type.edit_title', ['%name%' => $type->nameIn($context->locale)]), null, [new Action('cancel', UiSupport::label('leave.back'), $this->ui->url('nowo_working_time_leave_manage'))], tabs: $this->tabs('types')), bundle: 'NowoWorkingTimeBundle', layout: $this->ui->layout()),
                'form'     => $form->createView(),
                'archive'  => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_leave_archive'], 'wt_leave_archive_' . $id)->createView(),
                'archived' => $type->isArchived(),
                'id'       => $id,
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `POST {leave}/manage/types/{id}/archive`: archives or restores a type.
     */
    public function archive(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, WorkingTimePermission::LeaveManage, 'wt_leave_archive_' . $id, 'nowo_working_time_leave_archive');
            $archived = !$this->administration->type($id)->isArchived();
            $this->administration->archiveType($id, $archived);
            $this->ui->flash($request, 'success', $archived ? 'leave.flash.type_archived' : 'leave.flash.type_restored');

            return $this->ui->redirect('nowo_working_time_leave_type', ['id' => $id]);
        });
    }

    /**
     * `GET|POST {leave}/manage/allowances?year=&worker=`: the policies of a year, the forms to add a policy and a
     * line, and a worker's balances.
     */
    public function allowances(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $context                    = $this->access->context(WorkingTimePermission::LeaveManage);
            $year                       = $this->year($request);
            [$types, $groups, $workers] = $this->choices($context->tenant, $context->locale);
            $policy                     = $this->ui->form(AllowancePolicyType::class, ['year' => $year, 'scope' => 'tenant'], ['types' => $types, 'groups' => $groups, 'workers' => $workers], 'wt_leave_policy');
            $policy->handleRequest($request);
            if ($policy->isSubmitted() && $policy->isValid() && $this->addPolicy($policy)) {
                $this->ui->flash($request, 'success', 'leave.flash.policy_added');

                return $this->ui->redirect('nowo_working_time_leave_allowances', ['year' => $year]);
            }
            $line = $this->ui->form(AllowanceLineType::class, ['year' => $year, 'kind' => 'adjustment'], ['types' => $types, 'workers' => $workers], 'wt_leave_line');
            $line->handleRequest($request);
            if ($line->isSubmitted() && $line->isValid() && $this->addLine($line)) {
                $this->ui->flash($request, 'success', 'leave.flash.line_added');

                return $this->ui->redirect('nowo_working_time_leave_allowances', ['year' => $year]);
            }
            $names = [];
            foreach ($this->administration->types() as $type) {
                $names[$type->getId()] = $type;
            }
            $rows    = [];
            $removes = [];
            foreach ($this->administration->policies($year) as $row) {
                $type   = $names[$row->getLeaveTypeId()] ?? null;
                $rows[] = [
                    'type'   => $type?->nameIn($context->locale) ?? '',
                    'scope'  => $this->ui->trans('schedule.scope.' . $row->getScope()->value),
                    'target' => $row->getTargetId() ?? '',
                    'amount' => $type instanceof LeaveType ? $type->getUnit()->format($row->getAmount()) : (string) $row->getAmount(),
                    'note'   => $row->getNote() ?? '',
                ];
                $removes[] = ['form' => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_leave_policy_remove'], 'wt_policy_remove_' . $row->getId())->createView(), 'action' => $this->ui->url('nowo_working_time_leave_policy_remove', ['id' => $row->getId()]), 'label' => ($type?->nameIn($context->locale) ?? '') . ' · ' . ($row->getTargetId() ?? $this->ui->trans('schedule.scope.tenant'))];
            }
            $worker   = $request->query->getString('worker');
            $balances = $worker !== '' && in_array($worker, $workers, true) ? $this->balanceRows($context->tenant, new WorkerRef($worker), $year, $context->locale) : null;

            return $this->ui->render('@NowoWorkingTime/leave/allowances.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('leave.allowance.title', ['%year%' => $year]), UiSupport::label('leave.allowance.intro'), [
                        new Action('prev', UiSupport::label('leave.year_previous'), $this->ui->url('nowo_working_time_leave_allowances', ['year' => $year - 1])),
                        new Action('next', UiSupport::label('leave.year_next'), $this->ui->url('nowo_working_time_leave_allowances', ['year' => $year + 1])),
                    ], tabs: $this->tabs('allowances')),
                    [
                        new Column('type', UiSupport::label('leave.allowance.col.type'), required: true),
                        new Column('scope', UiSupport::label('leave.allowance.col.scope')),
                        new Column('target', UiSupport::label('leave.allowance.col.target'), priority: 2),
                        new Column('amount', UiSupport::label('leave.allowance.col.amount'), align: 'end', required: true),
                        new Column('note', UiSupport::label('leave.allowance.col.note'), priority: 3),
                    ],
                    $rows,
                    empty: new EmptyState(UiSupport::label('leave.allowance.empty')),
                    bundle: 'NowoWorkingTimeBundle',
                    layout: $this->ui->layout(),
                ),
                'policy_form' => $policy->createView(),
                'line_form'   => $line->createView(),
                'removes'     => $removes,
                'workers'     => $workers,
                'worker'      => $worker,
                'year'        => $year,
                'balances'    => $balances,
            ], $policy->isSubmitted() || $line->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `POST {leave}/manage/allowances/{id}/remove`.
     */
    public function removePolicy(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, WorkingTimePermission::LeaveManage, 'wt_policy_remove_' . $id, 'nowo_working_time_leave_policy_remove');
            $this->administration->removePolicy($id); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->ui->flash($request, 'success', 'leave.flash.policy_removed');

            return $this->ui->redirect('nowo_working_time_leave_allowances');
        });
    }

    /**
     * `GET|POST {settings}/leave`: the leave settings (version checked, a stale save answers 409).
     */
    public function settings(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$settings, $version] = $this->administration->settings();
            $form                 = $this->ui->form(LeaveSettingsType::class, $this->settingsData($settings, $version), [], 'wt_leave_settings');
            $form->handleRequest($request);
            if (!$form->isSubmitted()) {
                return $this->settingsPage($form, Response::HTTP_OK);
            }
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                try {
                    $this->administration->updateSettings(new LeaveSettingsInput(
                        ($data['enabled'] ?? false) === true,
                        RoundingMode::from(is_string($data['rounding'] ?? null) ? $data['rounding'] : 'nearest'),
                        is_int($data['protectedCarryMonths'] ?? null) ? $data['protectedCarryMonths'] : 0,
                        ($data['colleaguesSeeAbsences'] ?? false) === true,
                        ($data['medicalDocuments'] ?? false) === true,
                        is_int($data['attachmentRetentionMonths'] ?? null) ? $data['attachmentRetentionMonths'] : 0,
                        is_int($data['overtimeRatio'] ?? null) ? $data['overtimeRatio'] : 0,
                        is_string($data['planningDeadline'] ?? null) && $data['planningDeadline'] !== '' ? $data['planningDeadline'] : null,
                    ), is_numeric($data['version'] ?? null) ? (int) $data['version'] : -1);
                    $this->ui->flash($request, 'success', 'leave.flash.settings_saved');

                    return $this->ui->redirect('nowo_working_time_leave_settings');
                } catch (LeaveRefusedException $e) {
                    $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                } catch (StaleLeaveSettingsException) {
                    $this->ui->flash($request, 'error', 'settings.stale');
                    [$status, $headers]   = $this->ui->conflict();
                    [$settings, $version] = $this->administration->settings();

                    return $this->settingsPage($this->ui->form(LeaveSettingsType::class, $this->settingsData($settings, $version), [], 'wt_leave_settings'), $status, $headers);
                }
            }

            return $this->settingsPage($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, string> $headers
     */
    private function settingsPage(FormInterface $form, int $status, array $headers = []): Response
    {
        return $this->ui->render('@NowoWorkingTime/leave/settings.html.twig', [
            'page' => new SettingsPage(
                new PageHeader(UiSupport::label('leave.settings.title'), UiSupport::label('leave.settings.intro'), [], tabs: $this->tabs('settings')),
                [new SettingsSection('leave', UiSupport::label('leave.settings.section'), null, $form->createView(), null, UiSupport::label('settings.save'))],
                'leave',
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
        ], $status, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsData(LeaveSettings $settings, int $version): array
    {
        $input = $settings->input();

        return [
            'enabled'                   => $input->enabled,
            'rounding'                  => $input->rounding->value,
            'protectedCarryMonths'      => $input->protectedCarryMonths,
            'colleaguesSeeAbsences'     => $input->colleaguesSeeAbsences,
            'medicalDocuments'          => $input->medicalDocuments,
            'attachmentRetentionMonths' => $input->attachmentRetentionMonths,
            'overtimeRatio'             => $input->overtimeRatio,
            'planningDeadline'          => $input->planningDeadline,
            'version'                   => (string) $version,
        ];
    }

    private function balancePage(TenantRef $tenant, WorkerRef $worker, int $year, string $locale, string $tab, Label $title): Response
    {
        return $this->ui->render('@NowoWorkingTime/leave/balances.html.twig', [
            'page' => new ListPage(
                new PageHeader($title, UiSupport::label('leave.balance.intro'), [
                    new Action('prev', UiSupport::label('leave.year_previous'), $this->ui->url('nowo_working_time_leave', ['year' => $year - 1])),
                    new Action('next', UiSupport::label('leave.year_next'), $this->ui->url('nowo_working_time_leave', ['year' => $year + 1])),
                ], tabs: $this->tabs($tab)),
                $this->balanceColumns(),
                $this->balanceRows($tenant, $worker, $year, $locale),
                empty: new EmptyState(UiSupport::label('leave.balance.empty')),
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
        ]);
    }

    /**
     * @return list<Column>
     */
    private function balanceColumns(): array
    {
        return [
            new Column('type', UiSupport::label('leave.balance.col.type'), required: true),
            new Column('entitled', UiSupport::label('leave.balance.col.entitled'), align: 'end', required: true),
            new Column('taken', UiSupport::label('leave.balance.col.taken'), align: 'end', priority: 2),
            new Column('planned', UiSupport::label('leave.balance.col.planned'), align: 'end', priority: 2),
            new Column('pending', UiSupport::label('leave.balance.col.pending'), align: 'end', priority: 3),
            new Column('remaining', UiSupport::label('leave.balance.col.remaining'), align: 'end', required: true),
            new Column('unit', UiSupport::label('leave.balance.col.unit'), priority: 3),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function balanceRows(TenantRef $tenant, WorkerRef $worker, int $year, string $locale): array
    {
        $rows = [];
        foreach ($this->balances->balances($tenant, $worker, $year) as $balance) {
            $rows[] = [
                'type'      => $balance->typeName($locale),
                'entitled'  => $balance->entitled->format(),
                'taken'     => $balance->taken->format(),
                'planned'   => $balance->planned->format(),
                'pending'   => $balance->pending->format(),
                'remaining' => $balance->remaining()->format(),
                'unit'      => $this->ui->trans($balance->unit->labelKey()),
            ];
        }

        return $rows;
    }

    /**
     * @return array{array<string, string>, array<string, string>, array<string, string>} types, groups, workers (label => value)
     */
    private function choices(TenantRef $tenant, string $locale): array
    {
        $types = [];
        foreach ($this->administration->types() as $type) {
            if ($type->getSource() === AllowanceSource::Allowance && !$type->isArchived()) {
                $types[$type->nameIn($locale)] = $type->getId();
            }
        }
        $groups = [];
        foreach ($this->directory->groups($tenant) as [$group, $label]) {
            $groups[$label] = $group->value;
        }
        $workers = [];
        foreach ($this->directory->workers($tenant) as $worker) {
            $workers[$worker->displayName] = $worker->worker->value;
        }

        return [$types, $groups, $workers];
    }

    /**
     * @return FormInterface<mixed>
     */
    private function typeForm(?LeaveTypeInput $input, ?int $version = null): FormInterface
    {
        $data = $input instanceof LeaveTypeInput ? [
            'name'            => $input->name,
            'paid'            => $input->paid,
            'source'          => $input->source->value,
            'unit'            => $input->unit->value,
            'needsApproval'   => $input->needsApproval,
            'needsAttachment' => $input->needsAttachment,
            'medical'         => $input->medical,
            'colour'          => $input->colour,
            'maxPerRequest'   => $input->maxPerRequest === null ? null : $input->unit->toInput($input->maxPerRequest),
            'carryOver'       => $input->carryOver,
            'carryOverMax'    => $input->carryOverMax === null ? null : $input->unit->toInput($input->carryOverMax),
            'carryOverExpiry' => $input->carryOverExpiry,
        ] : ['paid' => true, 'needsApproval' => true, 'source' => 'none', 'unit' => 'working_days', 'colour' => 'blue'];
        $colours = [];
        foreach (LeaveType::COLOURS as $colour) {
            $colours[$colour] = $colour;
        }

        if ($version !== null) {
            $data['version'] = (string) $version;
        }

        return $this->ui->form(LeaveTypeType::class, $data, ['colours' => $colours, 'versioned' => $version !== null], 'wt_leave_type');
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function saveType(FormInterface $form, ?string $id): ?LeaveType
    {
        /** @var array<string, mixed> $data */
        $data   = $form->getData();
        $unit   = LeaveUnit::from(is_string($data['unit'] ?? null) ? $data['unit'] : 'working_days');
        $amount = static fn (mixed $value): ?int => is_int($value) || is_float($value) ? $unit->fromInput((float) $value) : null;
        try {
            return $this->administration->saveType($id, new LeaveTypeInput(
                is_string($data['name'] ?? null) ? $data['name'] : '',
                ($data['paid'] ?? false) === true,
                AllowanceSource::from(is_string($data['source'] ?? null) ? $data['source'] : 'none'),
                $unit,
                ($data['needsApproval'] ?? false) === true,
                ($data['needsAttachment'] ?? false) === true,
                ($data['medical'] ?? false) === true,
                is_string($data['colour'] ?? null) ? $data['colour'] : 'blue',
                $amount($data['maxPerRequest'] ?? null),
                ($data['carryOver'] ?? false) === true,
                $amount($data['carryOverMax'] ?? null),
                is_string($data['carryOverExpiry'] ?? null) && $data['carryOverExpiry'] !== '' ? $data['carryOverExpiry'] : null,
            ), is_numeric($data['version'] ?? null) ? (int) $data['version'] : null);
        } catch (LeaveRefusedException $e) {
            $form->addError(new FormError($this->ui->trans($e->key, $e->params))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return null;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function addPolicy(FormInterface $form): bool
    {
        /** @var array<string, mixed> $data */
        $data  = $form->getData();
        $scope = ScheduleScope::from(is_string($data['scope'] ?? null) ? $data['scope'] : 'tenant');
        try {
            $type = $this->administration->type(is_string($data['type'] ?? null) ? $data['type'] : '');
            $this->administration->addPolicy( // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $type->getId(),
                $scope,
                match ($scope) {
                    ScheduleScope::Group  => is_string($data['group'] ?? null) ? $data['group'] : throw new LeaveRefusedException('leave.allowance.error.target'),
                    ScheduleScope::Worker => is_string($data['worker'] ?? null) ? $data['worker'] : throw new LeaveRefusedException('leave.allowance.error.target'),
                    ScheduleScope::Tenant => null,
                },
                is_int($data['year'] ?? null) ? $data['year'] : 0,
                is_int($data['amount'] ?? null) || is_float($data['amount'] ?? null) ? $type->getUnit()->fromInput((float) $data['amount']) : -1,
                ($data['agreementEquivalent'] ?? false) === true,
                is_string($data['note'] ?? null) ? $data['note'] : null,
            );

            return true;
        } catch (LeaveRefusedException $e) {
            $form->addError(new FormError($this->ui->trans($e->key, $e->params))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return false;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function addLine(FormInterface $form): bool
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        try {
            $type = $this->administration->type(is_string($data['type'] ?? null) ? $data['type'] : '');
            $this->administration->addLine( // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $type->getId(),
                new WorkerRef(is_string($data['worker'] ?? null) ? $data['worker'] : ''),
                is_int($data['year'] ?? null) ? $data['year'] : 0,
                AllowanceLineKind::from(is_string($data['kind'] ?? null) ? $data['kind'] : 'adjustment'),
                is_int($data['amount'] ?? null) || is_float($data['amount'] ?? null) ? $type->getUnit()->fromInput((float) $data['amount']) : 0,
                is_string($data['reason'] ?? null) ? $data['reason'] : '',
                LocalDate::tryParse($data['expiresOn'] ?? null),
            );

            return true;
        } catch (LeaveRefusedException $e) {
            $form->addError(new FormError($this->ui->trans($e->key, $e->params))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return false;
    }

    private function year(Request $request): int
    {
        $year = $request->query->getInt('year', (int) $this->clock->now()->format('Y'));

        return $year < 2000 || $year > 2100 ? (int) $this->clock->now()->format('Y') : $year;
    }

    /**
     * Checks a CSRF-only action form (else 400) after the gate.
     */
    private function checked(Request $request, WorkingTimePermission $permission, string $name, string $tokenId): void
    {
        $this->access->context($permission);
        $form = $this->ui->form(ActionType::class, null, ['csrf_token_id' => $tokenId], $name);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new BadRequestHttpException('Invalid form.');
        }
    }

    /**
     * The leave area's navigation, with the entries the actor may open.
     */
    private function tabs(string $current): AreaNav
    {
        $items = [];
        if ($this->access->can(WorkingTimePermission::LeaveRequest)) {
            $items[] = new AreaNavItem(UiSupport::label('leave.nav.balances'), $this->ui->url('nowo_working_time_leave'), $current === 'balances');
        }
        if ($this->access->can(WorkingTimePermission::LeaveManage)) {
            $items[] = new AreaNavItem(UiSupport::label('leave.nav.types'), $this->ui->url('nowo_working_time_leave_manage'), $current === 'types');
            $items[] = new AreaNavItem(UiSupport::label('leave.nav.allowances'), $this->ui->url('nowo_working_time_leave_allowances'), $current === 'allowances');
        }
        if ($this->access->can(WorkingTimePermission::ManageSettings, false)) {
            $items[] = new AreaNavItem(UiSupport::label('leave.nav.settings'), $this->ui->url('nowo_working_time_leave_settings'), $current === 'settings');
        }

        return new AreaNav($items, UiSupport::label('leave.nav.label'));
    }
}
