<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A tenant's register settings (contract section 6.2) with the version the admin saw (a stale save answers 409). Enabling IP evidence needs the notice text shown to workers.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class SettingsType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void {
            $this->addTypedField('version', HiddenType::class, ['label' => false]);
            $this->addTypedField('enabled', CheckboxType::class, ['label' => 'settings.form.enabled', 'help' => 'settings.form.enabled_help', 'required' => false]);
            $this->addTypedField('breaksCounted', CheckboxType::class, ['label' => 'settings.form.breaks_counted', 'required' => false]);
            $this->addTypedField('flexMinutes', IntegerType::class, ['label' => 'settings.form.flex', 'attr' => ['min' => 0, 'max' => 240]]);
            $this->addTypedField('minBreakMinutes', IntegerType::class, ['label' => 'settings.form.min_break', 'help' => 'settings.form.min_break_help', 'attr' => ['min' => 0, 'max' => 120]]);
            $this->addTypedField('dailyOvertimeMinutes', IntegerType::class, ['label' => 'settings.form.daily_overtime', 'required' => false, 'attr' => ['min' => 1, 'max' => 720]]);
            $this->addTypedField('weeklyOvertimeMinutes', IntegerType::class, ['label' => 'settings.form.weekly_overtime', 'required' => false, 'attr' => ['min' => 1, 'max' => 5040]]);
            $this->addTypedField('taskTimerLink', CheckboxType::class, ['label' => 'settings.form.task_timer_link', 'help' => 'settings.form.task_timer_link_help', 'required' => false]);
            $this->addTypedField('remindersAllowed', CheckboxType::class, ['label' => 'settings.form.reminders_allowed', 'required' => false]);
            $this->addTypedField('monthlySummaryMandatory', CheckboxType::class, ['label' => 'settings.form.summary_mandatory', 'required' => false]);
            if ($options['ip_evidence_offered']) {
                $this->addTypedField('ipEvidence', CheckboxType::class, ['label' => 'settings.form.ip_evidence', 'help' => 'settings.form.ip_evidence_help', 'required' => false]);
                $this->addTypedField('ipNotice', TextareaType::class, ['label' => 'settings.form.ip_notice', 'help' => 'settings.form.ip_notice_help', 'required' => false, 'attr' => ['maxlength' => 2000, 'rows' => 3]]);
            }
            $this->addTypedField('retentionYears', ChoiceType::class, ['label' => 'settings.form.retention', 'help' => 'settings.form.retention_help', 'choices' => [4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => 10], 'choice_translation_domain' => false]);
            $this->addTypedField('correctionWindowDays', IntegerType::class, ['label' => 'settings.form.correction_window', 'attr' => ['min' => 1, 'max' => 31]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_settings`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'          => null,
            'csrf_protection'     => true,
            'csrf_token_id'       => 'nowo_working_time_settings',
            'allow_extra_fields'  => false,
            'translation_domain'  => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'ip_evidence_offered' => true,
        ]);
    }

    /**
     * Field names `wt_settings[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_settings';
    }
}
