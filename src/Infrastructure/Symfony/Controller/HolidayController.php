<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use InvalidArgumentException;
use JsonException;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\WorkingTimeBundle\Application\Holiday\HolidayAccess;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreview;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\MunicipalityDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\HolidayProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayCalendarType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayDayType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayImportConfirmType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayImportType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayProviderType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\WorkCentreType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function array_values;
use function count;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The holiday pages (contract section 11.1, `holidays_manage`): calendars and work centres, a calendar's days by hand,
 * CSV / ICS imports and provider imports (preview, then confirm; festivos.io's CC BY 4.0 attribution on both
 * screens), and the town search of the festivos.io import (cache only; loading the list is a button). They work with
 * the register and leave switched off (the stand-alone component).
 *
 * @internal
 */
final readonly class HolidayController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private HolidayAccess $access,
        private HolidayCalendarServiceInterface $holidays,
        private ClockInterface $clock,
        private ?MunicipalityDirectoryInterface $municipalities = null,
    ) {
    }

    /**
     * `GET|POST {holidays}`: calendars and work centres; the POST creates a calendar.
     */
    public function index(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context();
            $form = $this->ui->form(HolidayCalendarType::class, ['year' => (int) $this->clock->now()->format('Y'), 'scope' => 'national'], [], 'wt_holiday_calendar');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid() && ($calendar = $this->saveCalendar($form, null)) instanceof HolidayCalendar) {
                $this->ui->flash($request, 'success', 'holiday.flash.calendar_saved');

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $calendar->getId()]);
            }
            $centre = $this->centreForm(null);
            $centre->handleRequest($request);
            if ($centre->isSubmitted() && $centre->isValid() && $this->saveCentre($centre, null)) {
                $this->ui->flash($request, 'success', 'holiday.flash.centre_saved');

                return $this->ui->redirect('nowo_working_time_holidays');
            }

            return $this->indexPage($form, $centre, $form->isSubmitted() || $centre->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `GET|POST {holidays}/{id}`: a calendar's days and its forms; the POST changes the calendar.
     */
    public function calendar(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->holidays->calendar($id);
            $location = $calendar->location();
            $form     = $this->ui->form(HolidayCalendarType::class, [
                'name'         => $calendar->getName(),
                'scope'        => $calendar->getScope()->value,
                'year'         => $calendar->getYear(),
                'sourceNote'   => $calendar->getSourceNote(),
                'country'      => $location?->country,
                'subdivision'  => $location?->subdivision,
                'municipality' => $location?->municipality,
            ], [], 'wt_holiday_calendar');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid() && $this->saveCalendar($form, $id) instanceof HolidayCalendar) {
                $this->ui->flash($request, 'success', 'holiday.flash.calendar_saved');

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
            }

            return $this->calendarPage($calendar, $form, null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `POST {holidays}/{id}/remove`.
     */
    public function removeCalendar(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, 'wt_calendar_remove_' . $id, 'nowo_working_time_holiday_calendar_remove');
            $this->holidays->removeCalendar($id); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->ui->flash($request, 'success', 'holiday.flash.calendar_removed');

            return $this->ui->redirect('nowo_working_time_holidays');
        });
    }

    /**
     * `POST {holidays}/{id}/add`: a day by hand.
     */
    public function addDay(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->holidays->calendar($id);
            $form     = $this->ui->form(HolidayDayType::class, null, [], 'wt_holiday_day');
            $form->handleRequest($request);
            if (!$form->isSubmitted()) {
                throw new BadRequestHttpException('Invalid form.');
            }
            if ($form->isValid()) {
                [$date, $name] = $this->dayInput($form);
                try {
                    $this->holidays->addDay($id, $date ?? throw new HolidayRefusedException('holiday.day.error.date'), $name); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                    $this->ui->flash($request, 'success', 'holiday.flash.day_added');

                    return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
                } catch (HolidayRefusedException $e) {
                    $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
            }

            return $this->calendarPage($calendar, null, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * `GET|POST {holidays}/{id}/holiday/{day}/edit`.
     */
    public function editDay(Request $request, string $id, string $day): Response
    {
        return $this->ui->guard(function () use ($request, $id, $day): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->holidays->calendar($id);
            $current  = $this->findDay($id, $day);
            $form     = $this->ui->form(HolidayDayType::class, ['date' => $current->date()->value, 'name' => $current->getName()], [], 'wt_holiday_day');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                [$date, $name] = $this->dayInput($form);
                try {
                    $this->holidays->changeDay($id, $day, $date ?? throw new HolidayRefusedException('holiday.day.error.date'), $name);
                    $this->ui->flash($request, 'success', 'holiday.flash.day_changed');

                    return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
                } catch (HolidayRefusedException $e) {
                    $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
            }

            return $this->ui->render('@NowoWorkingTime/holidays/day.html.twig', [
                'page'     => $this->listPage(new PageHeader(UiSupport::label('holiday.day.edit_title', ['%calendar%' => $calendar->getName()]), null, [$this->back($id)]), [], []),
                'form'     => $form->createView(),
                'calendar' => $calendar,
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `POST {holidays}/{id}/holiday/{day}/remove`.
     */
    public function removeDay(Request $request, string $id, string $day): Response
    {
        return $this->ui->guard(function () use ($request, $id, $day): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, 'wt_day_remove_' . $day, 'nowo_working_time_holiday_day_remove');
            $this->holidays->removeDay($id, $day); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->ui->flash($request, 'success', 'holiday.flash.day_removed');

            return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
        });
    }

    /**
     * `POST {holidays}/{id}/import`: the preview of a CSV / ICS file (nothing saved).
     */
    public function import(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->holidays->calendar($id);
            $form     = $this->ui->form(HolidayImportType::class, null, [], 'wt_holiday_import');
            $form->handleRequest($request);
            $file = $form->isSubmitted() && $form->isValid() ? $form->get('file')->getData() : null;
            if (!$file instanceof UploadedFile) {
                $this->ui->flash($request, 'error', 'holiday.import.error.file');

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
            }
            try {
                $preview = $this->holidays->previewFile($id, (string) file_get_contents($file->getPathname()), $file->getClientOriginalName());
            } catch (HolidayRefusedException $e) {
                $this->ui->flashParams($request, 'error', $e->key, $e->params);

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
            }
            $rows = [];
            foreach ($preview->with(ImportStatus::New) as $row) {
                $rows[] = ['date' => $row->date, 'name' => $row->name];
            }
            $confirm = $this->ui->form(HolidayImportConfirmType::class, ['rows' => json_encode($rows, JSON_THROW_ON_ERROR)], [], 'wt_holiday_import_confirm');

            return $this->previewPage($calendar, $preview, $confirm, $this->ui->url('nowo_working_time_holiday_import_confirm', ['id' => $id]), null, $preview->error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * `POST {holidays}/{id}/import/confirm`: adds the preview's new rows (checked again).
     */
    public function importConfirm(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->ui->form(HolidayImportConfirmType::class, null, [], 'wt_holiday_import_confirm');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new BadRequestHttpException('Invalid form.');
            }
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            try {
                $result = $this->holidays->confirmFile($id, $this->rows(is_string($data['rows'] ?? null) ? $data['rows'] : ''));
            } catch (HolidayRefusedException $e) {
                $this->ui->flashParams($request, 'error', $e->key, $e->params);

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
            }
            $this->ui->flashParams($request, 'success', 'holiday.flash.imported', ['%added%' => $result->added]);

            return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
        });
    }

    /**
     * `POST {holidays}/{id}/provider/{key}`: the preview of a provider's holidays (one call out, nothing saved).
     */
    public function provider(Request $request, string $id, string $key): Response
    {
        return $this->ui->guard(function () use ($request, $id, $key): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendar = $this->holidays->calendar($id);
            $provider = $this->enabled($key);
            $form     = $this->ui->form(HolidayProviderType::class, null, [], 'wt_holiday_provider_' . $key);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new BadRequestHttpException('Invalid form.');
            }
            try {
                $location = $this->location($form);
                $preview  = $this->holidays->previewProvider($id, $key, $location, $request->getLocale());
            } catch (HolidayRefusedException $e) {
                $this->ui->flashParams($request, 'error', $e->key, $e->params);

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
            }
            $confirm = $this->ui->form(HolidayProviderType::class, ['country' => $location->country, 'subdivision' => $location->subdivision, 'municipality' => $location->municipality], ['hidden' => true], 'wt_holiday_provider_confirm_' . $key);

            return $this->previewPage($calendar, $preview, $confirm, $this->ui->url('nowo_working_time_holiday_provider_confirm', ['id' => $id, 'key' => $key]), $provider, Response::HTTP_OK);
        });
    }

    /**
     * `POST {holidays}/{id}/provider/{key}/confirm`: asks the provider again and syncs the calendar.
     */
    public function providerConfirm(Request $request, string $id, string $key): Response
    {
        return $this->ui->guard(function () use ($request, $id, $key): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->holidays->calendar($id);
            $this->enabled($key);
            $form = $this->ui->form(HolidayProviderType::class, null, ['hidden' => true], 'wt_holiday_provider_confirm_' . $key);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw new BadRequestHttpException('Invalid form.');
            }
            try {
                $result = $this->holidays->confirmProvider($id, $key, $this->location($form), $request->getLocale());
            } catch (HolidayRefusedException $e) {
                $this->ui->flashParams($request, 'error', $e->key, $e->params);

                return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
            }
            $this->ui->flashParams($request, 'success', $result->provisional ? 'holiday.flash.provider_imported_provisional' : 'holiday.flash.provider_imported', ['%added%' => $result->added, '%removed%' => $result->removed]);

            return $this->ui->redirect('nowo_working_time_holiday_calendar', ['id' => $id]);
        });
    }

    /**
     * `GET|POST {holidays}/centres/{id}`: a work centre.
     */
    public function centre(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $centre = $this->holidays->centre($id);
            $form   = $this->centreForm(['name' => $centre->getName(), 'calendars' => $centre->calendarIds(), 'default' => $centre->isDefault()]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid() && $this->saveCentre($form, $id)) {
                $this->ui->flash($request, 'success', 'holiday.flash.centre_saved');

                return $this->ui->redirect('nowo_working_time_holidays');
            }

            return $this->ui->render('@NowoWorkingTime/holidays/centre.html.twig', [
                'page'   => $this->listPage(new PageHeader(UiSupport::label('holiday.centre.edit_title', ['%name%' => $centre->getName()]), null, [new Action('cancel', UiSupport::label('holiday.back'), $this->ui->url('nowo_working_time_holidays'))]), [], []),
                'form'   => $form->createView(),
                'remove' => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_centre_remove'], 'wt_centre_remove_' . $id)->createView(),
                'id'     => $id,
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * `POST {holidays}/centres/{id}/remove`.
     */
    public function removeCentre(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, 'wt_centre_remove_' . $id, 'nowo_working_time_centre_remove');
            $this->holidays->removeCentre($id); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->ui->flash($request, 'success', 'holiday.flash.centre_removed');

            return $this->ui->redirect('nowo_working_time_holidays');
        });
    }

    /**
     * `GET {holidays}/municipalities?query=`: the town search (UI JSON; the cache only, never a call out).
     */
    public function municipalities(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context();

            return new JsonResponse(['results' => $this->municipalities?->search($request->query->getString('query')) ?? [], 'loaded' => $this->municipalities?->isLoaded() ?? false]);
        });
    }

    /**
     * `POST {holidays}/municipalities/refresh`: loads the town list (one call to festivos.io).
     */
    public function refreshMunicipalities(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->checked($request, 'wt_municipalities_refresh', 'nowo_working_time_municipalities_refresh');
            try {
                $count = $this->municipalities?->refresh() ?? 0;
                $this->ui->flashParams($request, 'success', 'holiday.flash.municipalities_loaded', ['%count%' => $count]);
            } catch (HolidayRefusedException $e) {
                $this->ui->flashParams($request, 'error', $e->key, $e->params);
            }

            return $this->ui->redirect('nowo_working_time_holidays');
        });
    }

    /**
     * @param FormInterface<mixed> $calendar
     * @param FormInterface<mixed> $centre
     */
    private function indexPage(FormInterface $calendar, FormInterface $centre, int $status): Response
    {
        $rows = [];
        foreach ($this->holidays->calendars() as $row) {
            $rows[] = [
                'id'       => $row->getId(),
                'year'     => (string) $row->getYear(),
                'name'     => $row->getName(),
                'scope'    => $this->ui->trans($row->getScope()->labelKey()),
                'provider' => $row->getProvider() . ($row->isProvisional() ? ' · ' . $this->ui->trans('holiday.provisional') : ''),
            ];
        }
        $centres = [];
        foreach ($this->holidays->centres() as $row) {
            $centres[] = ['name' => $row->getName(), 'default' => $row->isDefault(), 'href' => $this->ui->url('nowo_working_time_holiday_centre', ['id' => $row->getId()]), 'calendars' => count($row->calendarIds())];
        }

        return $this->ui->render('@NowoWorkingTime/holidays/index.html.twig', [
            'page' => $this->listPage(
                new PageHeader(UiSupport::label('holiday.title'), UiSupport::label('holiday.intro')),
                [
                    new Column('year', UiSupport::label('holiday.col.year'), required: true),
                    new Column('name', UiSupport::label('holiday.col.name'), required: true),
                    new Column('scope', UiSupport::label('holiday.col.scope'), priority: 2),
                    new Column('provider', UiSupport::label('holiday.col.provider'), priority: 3),
                ],
                $rows,
                fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::view($this->ui->url('nowo_working_time_holiday_calendar', ['id' => $row['id']]), UiSupport::label('holiday.open'))] : [],
                new EmptyState(UiSupport::label('holiday.empty')),
            ),
            'calendar_form'  => $calendar->createView(),
            'centre_form'    => $centre->createView(),
            'centres'        => $centres,
            'municipalities' => $this->municipalities instanceof MunicipalityDirectoryInterface ? [
                'loaded' => $this->municipalities->isLoaded(),
                'form'   => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_municipalities_refresh'], 'wt_municipalities_refresh')->createView(),
                'search' => $this->ui->url('nowo_working_time_holiday_municipalities'),
            ] : null,
        ], $status);
    }

    /**
     * @param FormInterface<mixed>|null $calendarForm
     * @param FormInterface<mixed>|null $dayForm
     */
    private function calendarPage(HolidayCalendar $calendar, ?FormInterface $calendarForm, ?FormInterface $dayForm, int $status): Response
    {
        $id      = $calendar->getId();
        $rows    = [];
        $removes = [];
        foreach ($this->holidays->days($id) as $day) {
            $rows[]    = $this->dayRow($day);
            $removes[] = [
                'form'   => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_holiday_day_remove'], 'wt_day_remove_' . $day->getId())->createView(),
                'action' => $this->ui->url('nowo_working_time_holiday_day_remove', ['id' => $id, 'day' => $day->getId()]),
                'label'  => $day->date()->value . ' · ' . $day->getName(),
            ];
        }
        $location  = $calendar->location();
        $providers = [];
        foreach ($this->holidays->providers() as $provider) {
            $providers[] = [
                'key'         => $provider->key(),
                'label'       => $provider->label(),
                'attribution' => $provider->attribution(),
                'action'      => $this->ui->url('nowo_working_time_holiday_provider', ['id' => $id, 'key' => $provider->key()]),
                'form'        => $this->ui->form(HolidayProviderType::class, ['country' => $location->country ?? 'ES', 'subdivision' => $location?->subdivision, 'municipality' => $location?->municipality], [], 'wt_holiday_provider_' . $provider->key())->createView(),
            ];
        }
        $calendarForm ??= $this->ui->form(HolidayCalendarType::class, ['name' => $calendar->getName(), 'scope' => $calendar->getScope()->value, 'year' => $calendar->getYear(), 'sourceNote' => $calendar->getSourceNote(), 'country' => $location?->country, 'subdivision' => $location?->subdivision, 'municipality' => $location?->municipality], [], 'wt_holiday_calendar');
        $dayForm ??= $this->ui->form(HolidayDayType::class, null, [], 'wt_holiday_day');

        return $this->ui->render('@NowoWorkingTime/holidays/calendar.html.twig', [
            'page' => $this->listPage(
                new PageHeader(UiSupport::label('holiday.calendar.title', ['%name%' => $calendar->getName(), '%year%' => $calendar->getYear()]), $calendar->getSourceNote(), [new Action('cancel', UiSupport::label('holiday.back'), $this->ui->url('nowo_working_time_holidays'))]),
                [
                    new Column('date', UiSupport::label('holiday.col.date'), required: true),
                    new Column('name', UiSupport::label('holiday.col.name'), required: true),
                    new Column('origin', UiSupport::label('holiday.col.origin'), priority: 2),
                    new Column('source', UiSupport::label('holiday.col.source'), priority: 3),
                ],
                $rows,
                fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::edit($this->ui->url('nowo_working_time_holiday_day_edit', ['id' => $id, 'day' => $row['id']]), UiSupport::label('holiday.day.edit'))] : [],
                new EmptyState(UiSupport::label('holiday.calendar.empty')),
            ),
            'calendar'      => $calendar,
            'calendar_form' => $calendarForm->createView(),
            'day_form'      => $dayForm->createView(),
            'day_action'    => $this->ui->url('nowo_working_time_holiday_day_add', ['id' => $id]),
            'import_form'   => $this->ui->form(HolidayImportType::class, null, [], 'wt_holiday_import')->createView(),
            'import_action' => $this->ui->url('nowo_working_time_holiday_import', ['id' => $id]),
            'providers'     => $providers,
            'removes'       => $removes,
            'remove_form'   => $this->ui->form(ActionType::class, null, ['csrf_token_id' => 'nowo_working_time_holiday_calendar_remove'], 'wt_calendar_remove_' . $id)->createView(),
            'remove_action' => $this->ui->url('nowo_working_time_holiday_calendar_remove', ['id' => $id]),
        ], $status);
    }

    /**
     * @param FormInterface<mixed> $confirm
     */
    private function previewPage(HolidayCalendar $calendar, ImportPreview $preview, FormInterface $confirm, string $action, ?HolidayProviderInterface $provider, int $status): Response
    {
        $rows = [];
        foreach ($preview->rows as $row) {
            $rows[] = ['date' => $row->date, 'name' => $row->name, 'status' => $this->ui->trans($row->status->labelKey()), 'scope' => $row->scope === null ? '' : $this->ui->trans($row->scope->labelKey()), 'source' => $row->sourceRef ?? ''];
        }
        $changes = $preview->with(ImportStatus::New) !== [] || $preview->with(ImportStatus::Removed) !== [];

        return $this->ui->render('@NowoWorkingTime/holidays/preview.html.twig', [
            'page' => $this->listPage(
                new PageHeader(UiSupport::label('holiday.import.preview_title', ['%name%' => $calendar->getName(), '%year%' => $calendar->getYear()]), $preview->fetched?->place, [$this->back($calendar->getId())]),
                [
                    new Column('date', UiSupport::label('holiday.col.date'), required: true),
                    new Column('name', UiSupport::label('holiday.col.name'), required: true),
                    new Column('status', UiSupport::label('holiday.col.status'), required: true),
                    new Column('scope', UiSupport::label('holiday.col.scope'), priority: 2),
                    new Column('source', UiSupport::label('holiday.col.source'), priority: 3),
                ],
                $rows,
                [],
                new EmptyState(UiSupport::label('holiday.import.nothing')),
            ),
            'error'       => $preview->error,
            'provisional' => $preview->fetched->provisional ?? false,
            'attribution' => $preview->fetched->attribution ?? $provider?->attribution(),
            'confirm'     => $preview->error === null && $changes ? $confirm->createView() : null,
            'action'      => $action,
        ], $status);
    }

    /**
     * @return array<string, string>
     */
    private function dayRow(HolidayDay $day): array
    {
        return [
            'id'     => $day->getId(),
            'date'   => $day->date()->value,
            'name'   => $day->getName(),
            'origin' => $this->ui->trans($day->getOrigin()->labelKey()),
            'source' => $day->getSourceRef() ?? '',
        ];
    }

    /**
     * @param iterable<Column> $columns
     * @param iterable<mixed> $rows
     * @param callable|iterable<Action> $rowActions
     */
    private function listPage(PageHeader $header, iterable $columns, iterable $rows, callable|iterable $rowActions = [], ?EmptyState $empty = null): ListPage
    {
        return new ListPage($header, $columns, $rows, rowActions: $rowActions, empty: $empty, bundle: 'NowoWorkingTimeBundle', layout: $this->ui->layout());
    }

    private function back(string $id): Action
    {
        return new Action('cancel', UiSupport::label('holiday.back_calendar'), $this->ui->url('nowo_working_time_holiday_calendar', ['id' => $id]));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function saveCalendar(FormInterface $form, ?string $id): ?HolidayCalendar
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        try {
            $country = is_string($data['country'] ?? null) ? trim($data['country']) : '';

            return $this->holidays->saveCalendar($id, new HolidayCalendarInput(
                is_string($data['name'] ?? null) ? $data['name'] : '',
                HolidayScope::from(is_string($data['scope'] ?? null) ? $data['scope'] : 'national'),
                is_int($data['year'] ?? null) ? $data['year'] : 0,
                is_string($data['sourceNote'] ?? null) ? $data['sourceNote'] : null,
                $country === '' ? null : new HolidayLocation($country, is_string($data['subdivision'] ?? null) ? trim($data['subdivision']) : null, is_string($data['municipality'] ?? null) && trim($data['municipality']) !== '' ? trim($data['municipality']) : null),
            ));
        } catch (HolidayRefusedException $e) {
            $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        } catch (InvalidArgumentException $e) {
            $form->addError(new FormError($this->ui->trans($e->getMessage()))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return FormInterface<mixed>
     */
    private function centreForm(?array $data): FormInterface
    {
        $calendars = [];
        foreach ($this->holidays->calendars() as $calendar) {
            $calendars[$calendar->getYear() . ' · ' . $calendar->getName()] = $calendar->getId();
        }

        return $this->ui->form(WorkCentreType::class, $data, ['calendars' => $calendars], 'wt_work_centre');
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function saveCentre(FormInterface $form, ?string $id): bool
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        /** @var list<string> $calendars the form's choices are the tenant's calendar ids */
        $calendars = is_array($data['calendars'] ?? null) ? array_values($data['calendars']) : [];
        try {
            $this->holidays->saveCentre($id, is_string($data['name'] ?? null) ? $data['name'] : '', $calendars, ($data['default'] ?? false) === true);

            return true;
        } catch (HolidayRefusedException $e) {
            $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return false;
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return array{LocalDate|null, string}
     */
    private function dayInput(FormInterface $form): array
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();

        return [LocalDate::tryParse($data['date'] ?? null), is_string($data['name'] ?? null) ? $data['name'] : ''];
    }

    private function findDay(string $calendarId, string $dayId): HolidayDay
    {
        foreach ($this->holidays->days($calendarId) as $day) {
            if ($day->getId() === $dayId) {
                return $day;
            }
        }

        throw new NotAvailableException();
    }

    private function enabled(string $key): HolidayProviderInterface
    {
        foreach ($this->holidays->providers() as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        throw new NotAvailableException();
    }

    /**
     * Checks a CSRF-only action form (else 400) after the gate.
     */
    private function checked(Request $request, string $name, string $tokenId): void
    {
        $this->access->context();
        $form = $this->ui->form(ActionType::class, null, ['csrf_token_id' => $tokenId], $name);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new BadRequestHttpException('Invalid form.');
        }
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @throws HolidayRefusedException
     */
    private function location(FormInterface $form): HolidayLocation
    {
        /** @var array<string, mixed> $data */
        $data = $form->getData();
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        try {
            return new HolidayLocation($text('country') ?? '', $text('subdivision'), $text('municipality'));
        } catch (InvalidArgumentException $e) {
            throw new HolidayRefusedException($e->getMessage());
        }
    }

    /**
     * The confirmed rows posted back (JSON list of {date, name}); anything else is dropped.
     *
     * @return list<array{date: string, name: string}>
     */
    private function rows(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $rows = [];
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (is_array($row) && is_string($row['date'] ?? null) && is_string($row['name'] ?? null)) {
                $rows[] = ['date' => $row['date'], 'name' => $row['name']];
            }
        }

        return $rows;
    }
}
