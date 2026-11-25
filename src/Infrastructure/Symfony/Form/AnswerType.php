<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The worker's answer to a manager-recorded correction (spec 198 FR-016): acknowledge, or contest with a comment.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class AnswerType extends AbstractType
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
            $this->addTypedField('answer', ChoiceType::class, [
                'label'    => 'correction.form.answer',
                'choices'  => ['correction.answer.acknowledge' => 'acknowledge', 'correction.answer.contest' => 'contest'],
                'expanded' => true,
            ]);
            $this->addTypedField('comment', TextareaType::class, ['label' => 'correction.form.comment', 'required' => false, 'attr' => ['maxlength' => 500, 'rows' => 2]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_answer`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_answer',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `wt_answer[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_answer';
    }
}
