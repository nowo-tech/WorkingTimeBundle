<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A leave type: name, paid, what it draws from, unit, approval, document, medical flag, colour, maximum per request and carry-over (no field for any health detail, contract section 14.3).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class LeaveTypeType extends AbstractType
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
            $this->addTypedField('name', TextType::class, ['label' => 'leave.type.form.name', 'attr' => ['maxlength' => 80]]);
            $this->addTypedField('paid', CheckboxType::class, ['label' => 'leave.type.form.paid', 'required' => false]);
            $this->addTypedField('source', ChoiceType::class, ['label' => 'leave.type.form.source', 'choices' => ['leave.source.none' => 'none', 'leave.source.allowance' => 'allowance', 'leave.source.overtime' => 'overtime']]);
            $this->addTypedField('unit', ChoiceType::class, ['label' => 'leave.type.form.unit', 'choices' => ['leave.unit.working_days' => 'working_days', 'leave.unit.natural_days' => 'natural_days', 'leave.unit.hours' => 'hours']]);
            $this->addTypedField('needsApproval', CheckboxType::class, ['label' => 'leave.type.form.needs_approval', 'required' => false]);
            $this->addTypedField('needsAttachment', CheckboxType::class, ['label' => 'leave.type.form.needs_attachment', 'required' => false]);
            $this->addTypedField('medical', CheckboxType::class, ['label' => 'leave.type.form.medical', 'required' => false, 'help' => 'leave.type.form.medical_help']);
            $this->addTypedField('colour', ChoiceType::class, ['label' => 'leave.type.form.colour', 'choices' => $options['colours'], 'choice_translation_domain' => false]);
            $this->addTypedField('maxPerRequest', NumberType::class, ['label' => 'leave.type.form.max_per_request', 'required' => false, 'scale' => 2, 'help' => 'leave.type.form.amount_help']);
            $this->addTypedField('carryOver', CheckboxType::class, ['label' => 'leave.type.form.carry_over', 'required' => false]);
            $this->addTypedField('carryOverMax', NumberType::class, ['label' => 'leave.type.form.carry_over_max', 'required' => false, 'scale' => 2]);
            $this->addTypedField('carryOverExpiry', TextType::class, ['label' => 'leave.type.form.carry_over_expiry', 'required' => false, 'help' => 'leave.type.form.carry_over_expiry_help', 'attr' => ['maxlength' => 5, 'placeholder' => '03-31']]);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_leave_type`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_leave_type',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'versioned'          => false,
            'colours'            => [],
        ]);
    }

    /**
     * Field names `wt_leave_type[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_leave_type';
    }
}
