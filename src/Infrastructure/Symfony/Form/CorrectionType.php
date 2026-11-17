<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A correction of a day (spec 198 FR-014 / FR-016): change a segment's times, add a missing segment or void one, with a reason (10–500 characters). Times are local to the day's time zone; an end before the start is the next day (night shift). Used by the worker's request and the manager's record.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class CorrectionType extends AbstractType
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
            $this->addTypedField('operation', ChoiceType::class, [
                'label'   => 'correction.form.operation',
                'choices' => ['correction.operation.amend' => 'amend', 'correction.operation.add' => 'add', 'correction.operation.void' => 'void'],
            ]);
            $this->addTypedField('segment', ChoiceType::class, [
                'label'                     => 'correction.form.segment',
                'choices'                   => $options['segments'],
                'choice_translation_domain' => false,
                'required'                  => false,
                'placeholder'               => 'correction.form.segment_none',
            ]);
            $this->addTypedField('segmentType', ChoiceType::class, [
                'label'   => 'correction.form.segment_type',
                'choices' => ['segment.type.work' => 'work', 'segment.type.break' => 'break'],
            ]);
            $this->addTypedField('start', TimeType::class, ['label' => 'correction.form.start', 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i']);
            $this->addTypedField('end', TimeType::class, ['label' => 'correction.form.end', 'required' => false, 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i']);
            $this->addTypedField('reason', TextareaType::class, ['label' => 'correction.form.reason', 'help' => 'correction.form.reason_help', 'attr' => ['maxlength' => 500, 'minlength' => 10, 'rows' => 3]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_correction`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_correction',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'segments'           => [],
        ]);
    }

    /**
     * Field names `wt_correction[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_correction';
    }
}
