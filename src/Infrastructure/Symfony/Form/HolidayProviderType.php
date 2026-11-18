<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The place a provider is asked about (preview), or the same place again as hidden fields (confirm).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class HolidayProviderType extends AbstractType
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
            $type = $options['hidden'] === true ? HiddenType::class : TextType::class;
            $this->addTypedField('country', $type, $options['hidden'] === true ? [] : ['label' => 'holiday.calendar.form.country', 'attr' => ['maxlength' => 2]]);
            $this->addTypedField('subdivision', $type, $options['hidden'] === true ? [] : ['label' => 'holiday.calendar.form.subdivision', 'required' => false, 'attr' => ['maxlength' => 6]]);
            $this->addTypedField('municipality', $type, $options['hidden'] === true ? [] : ['label' => 'holiday.calendar.form.municipality', 'required' => false, 'attr' => ['maxlength' => 5, 'inputmode' => 'numeric']]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_holiday_provider`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_holiday_provider',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'hidden'             => false,
        ]);
    }

    /**
     * Field names `wt_holiday_provider[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_holiday_provider';
    }
}
