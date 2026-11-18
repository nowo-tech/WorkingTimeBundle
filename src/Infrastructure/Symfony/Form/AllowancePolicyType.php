<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An allowance policy: the type, who it applies to (the tenant, a group, a worker), the year and the amount (days or hours), with the agreement-equivalent mark and its note.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class AllowancePolicyType extends AbstractType
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
            $this->addTypedField('type', ChoiceType::class, ['label' => 'leave.allowance.form.type', 'choices' => $options['types'], 'choice_translation_domain' => false]);
            $this->addTypedField('scope', ChoiceType::class, ['label' => 'leave.allowance.form.scope', 'choices' => ['schedule.scope.tenant' => 'tenant', 'schedule.scope.group' => 'group', 'schedule.scope.worker' => 'worker']]);
            $this->addTypedField('group', ChoiceType::class, ['label' => 'schedule.form.group', 'choices' => $options['groups'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'schedule.form.none']);
            $this->addTypedField('worker', ChoiceType::class, ['label' => 'schedule.form.worker', 'choices' => $options['workers'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'schedule.form.none']);
            $this->addTypedField('year', IntegerType::class, ['label' => 'leave.allowance.form.year', 'attr' => ['min' => 2000, 'max' => 2100]]);
            $this->addTypedField('amount', NumberType::class, ['label' => 'leave.allowance.form.amount', 'scale' => 2, 'help' => 'leave.type.form.amount_help']);
            $this->addTypedField('agreementEquivalent', CheckboxType::class, ['label' => 'leave.allowance.form.agreement_equivalent', 'required' => false]);
            $this->addTypedField('note', TextType::class, ['label' => 'leave.allowance.form.note', 'required' => false, 'attr' => ['maxlength' => 255]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_leave_policy`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_leave_policy',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'types'              => [],
            'groups'             => [],
            'workers'            => [],
        ]);
    }

    /**
     * Field names `wt_leave_policy[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_leave_policy';
    }
}
