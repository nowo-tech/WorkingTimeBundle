<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A work centre: name, the holiday calendars that apply there, and whether it is the default.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class WorkCentreType extends AbstractType
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
            $this->addTypedField('name', TextType::class, ['label' => 'holiday.centre.form.name', 'attr' => ['maxlength' => 80]]);
            $this->addTypedField('calendars', ChoiceType::class, ['label' => 'holiday.centre.form.calendars', 'choices' => $options['calendars'], 'choice_translation_domain' => false, 'multiple' => true, 'expanded' => true, 'required' => false]);
            $this->addTypedField('default', CheckboxType::class, ['label' => 'holiday.centre.form.default', 'required' => false]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_work_centre`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_work_centre',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'calendars'          => [],
        ]);
    }

    /**
     * Field names `wt_work_centre[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_work_centre';
    }
}
