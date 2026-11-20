<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Application\Register\Port\TriggerInspectorInterface;
use Nowo\WorkingTimeBundle\Application\Register\WorkerPreferences;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\SettingsRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterSettingsEditorInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\PreferencesType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\SettingsType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_int;
use function is_numeric;
use function is_string;
use function trim;

/**
 * The tenant's register settings (`manage_settings`; reachable while the register is off: it turns it on) with the
 * version the admin saw (a stale save answers 409 + Retry-After with the form again) and the warning while the
 * append-only triggers are missing; and the worker's own preferences.
 *
 * @internal
 */
final readonly class SettingsController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private RegisterSettingsEditorInterface $editor,
        private WorkerPreferences $preferences,
        private TriggerInspectorInterface $triggers,
        private bool $ipEvidenceOffered = true,
    ) {
    }

    /**
     * `GET|POST {settings}`.
     */
    public function settings(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$settings, $version] = $this->editor->get();
            $form                 = $this->ui->form(SettingsType::class, $this->data($settings, $version, $request->getLocale()), ['ip_evidence_offered' => $this->ipEvidenceOffered]);
            $form->handleRequest($request);
            if (!$form->isSubmitted()) {
                return $this->page($form, Response::HTTP_OK);
            }
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                try {
                    $this->editor->update($this->input($data, $request->getLocale(), $settings->ipNotices()), is_numeric($data['version'] ?? null) ? (int) $data['version'] : -1);
                    $this->ui->flash($request, 'success', 'settings.saved');

                    return $this->ui->redirect('nowo_working_time_settings');
                } catch (SettingsRefusedException $e) {
                    $form->addError(new FormError($this->ui->trans($e->key))); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                } catch (StaleSettingsException) {
                    $this->ui->flash($request, 'error', 'settings.stale');
                    [$status, $headers]   = $this->ui->conflict();
                    [$settings, $version] = $this->editor->get();

                    return $this->page($this->ui->form(SettingsType::class, $this->data($settings, $version, $request->getLocale()), ['ip_evidence_offered' => $this->ipEvidenceOffered]), $status, $headers);
                }
            }

            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * `GET|POST {register}/preferences`.
     */
    public function preferences(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$preference, $allowed, $mandatory] = $this->preferences->get();
            $form                               = $this->ui->form(PreferencesType::class, ['reminders' => $preference->wantsReminders(), 'monthlySummary' => $preference->wantsMonthlySummary() || $mandatory], ['reminders_allowed' => $allowed, 'summary_mandatory' => $mandatory]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{reminders: mixed, monthlySummary: mixed} $data */
                $data = $form->getData();
                $this->preferences->update($data['reminders'] === true, $data['monthlySummary'] === true);
                $this->ui->flash($request, 'success', 'preferences.saved');

                return $this->ui->redirect('nowo_working_time_preferences');
            }

            return $this->ui->render('@NowoWorkingTimeBundle/register/preferences.html.twig', [
                'page' => new FormPage(
                    new PageHeader(UiSupport::label('preferences.title'), UiSupport::label('preferences.intro'), [], tabs: $this->ui->tabs('preferences')),
                    $form->createView(),
                    UiSupport::label('preferences.save'),
                    bundle: 'NowoWorkingTimeBundle',
                    layout: $this->ui->layout(),
                ),
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string, string> $headers
     */
    private function page(FormInterface $form, int $status, array $headers = []): Response
    {
        return $this->ui->render('@NowoWorkingTimeBundle/register/settings.html.twig', [
            'page' => new SettingsPage(
                new PageHeader(UiSupport::label('settings.title'), UiSupport::label('settings.intro'), []),
                [new SettingsSection('register', UiSupport::label('settings.section.register'), null, $form->createView(), UiSupport::label('settings.section.register_intro'), UiSupport::label('settings.save'))],
                'register',
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'missing_triggers' => $this->triggers->missing(),
        ], $status, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(RegisterSettings $settings, int $version, string $locale): array
    {
        $input = $settings->toInput();

        return [
            'version'                 => (string) $version,
            'enabled'                 => $input->enabled,
            'breaksCounted'           => $input->breaksCounted,
            'flexMinutes'             => $input->flexMinutes,
            'minBreakMinutes'         => $input->minBreakMinutes,
            'dailyOvertimeMinutes'    => $input->dailyOvertimeMinutes,
            'weeklyOvertimeMinutes'   => $input->weeklyOvertimeMinutes,
            'taskTimerLink'           => $input->taskTimerLink,
            'remindersAllowed'        => $input->remindersAllowed,
            'monthlySummaryMandatory' => $input->monthlySummaryMandatory,
            'ipEvidence'              => $input->ipEvidence,
            'ipNotice'                => $settings->ipNoticeFor($locale),
            'retentionYears'          => $input->retentionYears,
            'correctionWindowDays'    => $input->correctionWindowDays,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $notices the stored notices of the other locales (kept)
     */
    private function input(array $data, string $locale, array $notices): RegisterSettingsInput
    {
        $int    = static fn (string $key, int $default): int => is_int($data[$key] ?? null) ? $data[$key] : $default;
        $null   = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;
        $notice = is_string($data['ipNotice'] ?? null) ? trim($data['ipNotice']) : '';
        if ($notice === '') {
            unset($notices[$locale]);
        } else {
            $notices[$locale] = $notice;
        }

        return new RegisterSettingsInput(
            ($data['enabled'] ?? false) === true,
            ($data['breaksCounted'] ?? false) === true,
            $int('flexMinutes', 0),
            $int('minBreakMinutes', 0),
            $null('dailyOvertimeMinutes'),
            $null('weeklyOvertimeMinutes'),
            ($data['taskTimerLink'] ?? false) === true,
            ($data['remindersAllowed'] ?? false) === true,
            ($data['monthlySummaryMandatory'] ?? false) === true,
            ($data['ipEvidence'] ?? false) === true,
            $notices,
            $notice === '' ? null : $locale,
            $int('retentionYears', 4),
            $int('correctionWindowDays', 31),
        );
    }
}
