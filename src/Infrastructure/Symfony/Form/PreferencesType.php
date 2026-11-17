<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A worker's register preferences: clock reminders (only when the tenant allows them) and the monthly summary (always on when the tenant made it mandatory).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class PreferencesType extends AbstractType
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
            $this->addTypedField('reminders', CheckboxType::class, ['label' => 'preferences.reminders', 'help' => 'preferences.reminders_help', 'required' => false, 'disabled' => !$options['reminders_allowed']]);
            $this->addTypedField('monthlySummary', CheckboxType::class, ['label' => 'preferences.monthly_summary', 'help' => 'preferences.monthly_summary_help', 'required' => false, 'disabled' => $options['summary_mandatory']]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_preferences`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_preferences',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'reminders_allowed'  => false,
            'summary_mandatory'  => false,
        ]);
    }

    /**
     * Field names `wt_preferences[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_preferences';
    }
}
