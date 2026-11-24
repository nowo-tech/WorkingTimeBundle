<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A holiday of a calendar added or edited by hand.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class HolidayDayType extends AbstractType
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
            $this->addTypedField('date', DateType::class, ['label' => 'holiday.day.form.date', 'widget' => 'single_text', 'input' => 'string']);
            $this->addTypedField('name', TextType::class, ['label' => 'holiday.day.form.name', 'attr' => ['maxlength' => 120]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_holiday_day`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_holiday_day',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,

        ]);
    }

    /**
     * Field names `wt_holiday_day[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_holiday_day';
    }
}
