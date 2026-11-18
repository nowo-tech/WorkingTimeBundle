<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A line on a worker's allowance: an adjustment (signed), a carry-over or a protected carry-over, with a reason and an optional expiry date.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class AllowanceLineType extends AbstractType
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
            $this->addTypedField('worker', ChoiceType::class, ['label' => 'schedule.form.worker', 'choices' => $options['workers'], 'choice_translation_domain' => false]);
            $this->addTypedField('type', ChoiceType::class, ['label' => 'leave.allowance.form.type', 'choices' => $options['types'], 'choice_translation_domain' => false]);
            $this->addTypedField('year', IntegerType::class, ['label' => 'leave.allowance.form.year', 'attr' => ['min' => 2000, 'max' => 2100]]);
            $this->addTypedField('kind', ChoiceType::class, ['label' => 'leave.line.form.kind', 'choices' => ['leave.line_kind.adjustment' => 'adjustment', 'leave.line_kind.carry_over' => 'carry_over', 'leave.line_kind.protected_carry_over' => 'protected_carry_over']]);
            $this->addTypedField('amount', NumberType::class, ['label' => 'leave.allowance.form.amount', 'scale' => 2, 'help' => 'leave.line.form.amount_help']);
            $this->addTypedField('reason', TextType::class, ['label' => 'leave.line.form.reason', 'help' => 'leave.no_health_hint', 'attr' => ['maxlength' => 255]]);
            $this->addTypedField('expiresOn', DateType::class, ['label' => 'leave.line.form.expires_on', 'widget' => 'single_text', 'input' => 'string', 'required' => false, 'help' => 'leave.line.form.expires_on_help']);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_leave_line`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_leave_line',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'types'              => [],
            'workers'            => [],
        ]);
    }

    /**
     * Field names `wt_leave_line[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_leave_line';
    }
}
