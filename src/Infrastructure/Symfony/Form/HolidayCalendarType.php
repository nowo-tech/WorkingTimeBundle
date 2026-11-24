<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A holiday calendar: name, scope, year, source note and the place the providers look up.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class HolidayCalendarType extends AbstractType
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
            $this->addTypedField('name', TextType::class, ['label' => 'holiday.calendar.form.name', 'attr' => ['maxlength' => 80]]);
            $this->addTypedField('scope', ChoiceType::class, ['label' => 'holiday.calendar.form.scope', 'choices' => ['holiday.scope.national' => 'national', 'holiday.scope.regional' => 'regional', 'holiday.scope.local' => 'local']]);
            $this->addTypedField('year', IntegerType::class, ['label' => 'holiday.calendar.form.year', 'attr' => ['min' => 2000, 'max' => 2100]]);
            $this->addTypedField('country', TextType::class, ['label' => 'holiday.calendar.form.country', 'required' => false, 'help' => 'holiday.calendar.form.country_help', 'attr' => ['maxlength' => 2, 'placeholder' => 'ES']]);
            $this->addTypedField('subdivision', TextType::class, ['label' => 'holiday.calendar.form.subdivision', 'required' => false, 'help' => 'holiday.calendar.form.subdivision_help', 'attr' => ['maxlength' => 6, 'placeholder' => 'ES-MD']]);
            $this->addTypedField('municipality', TextType::class, ['label' => 'holiday.calendar.form.municipality', 'required' => false, 'help' => 'holiday.calendar.form.municipality_help', 'attr' => ['maxlength' => 5, 'inputmode' => 'numeric', 'placeholder' => '28079']]);
            $this->addTypedField('sourceNote', TextType::class, ['label' => 'holiday.calendar.form.source_note', 'required' => false, 'attr' => ['maxlength' => 255]]);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_holiday_calendar`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_holiday_calendar',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'versioned'          => false,

        ]);
    }

    /**
     * Field names `wt_holiday_calendar[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_holiday_calendar';
    }
}
