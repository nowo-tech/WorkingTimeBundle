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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The tenant's leave settings (contract section 6.6) with the version the admin saw.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class LeaveSettingsType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('enabled', CheckboxType::class, ['label' => 'leave.settings.form.enabled', 'required' => false, 'help' => 'leave.settings.form.enabled_help']);
            $this->addTypedField('rounding', ChoiceType::class, ['label' => 'leave.settings.form.rounding', 'choices' => ['leave.rounding.nearest' => 'nearest', 'leave.rounding.up' => 'up', 'leave.rounding.down' => 'down']]);
            $this->addTypedField('protectedCarryMonths', IntegerType::class, ['label' => 'leave.settings.form.protected_carry_months', 'attr' => ['min' => 1, 'max' => 60]]);
            $this->addTypedField('colleaguesSeeAbsences', CheckboxType::class, ['label' => 'leave.settings.form.colleagues_see_absences', 'required' => false]);
            $this->addTypedField('medicalDocuments', CheckboxType::class, ['label' => 'leave.settings.form.medical_documents', 'required' => false]);
            $this->addTypedField('attachmentRetentionMonths', IntegerType::class, ['label' => 'leave.settings.form.attachment_retention_months', 'attr' => ['min' => 1, 'max' => 48]]);
            $this->addTypedField('overtimeRatio', IntegerType::class, ['label' => 'leave.settings.form.overtime_ratio', 'attr' => ['min' => 1, 'max' => 400]]);
            $this->addTypedField('planningDeadline', TextType::class, ['label' => 'leave.settings.form.planning_deadline', 'required' => false, 'attr' => ['maxlength' => 5, 'placeholder' => '04-30']]);
            $this->addTypedField('version', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_leave_settings`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_leave_settings',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,

        ]);
    }

    /**
     * Field names `wt_leave_settings[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_leave_settings';
    }
}
