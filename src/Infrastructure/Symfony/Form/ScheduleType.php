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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A dated schedule row (spec 198 FR-003): scope (tenant default, a group, one worker), the first valid date, the time zone, the ranges of each weekday (`09:00-14:00, 15:00-18:00`), optional daily / weekly targets and the flexible band.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class ScheduleType extends AbstractType
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
            $this->addTypedField('scope', ChoiceType::class, ['label' => 'schedule.form.scope', 'choices' => ['schedule.scope.tenant' => 'tenant', 'schedule.scope.group' => 'group', 'schedule.scope.worker' => 'worker']]);
            $this->addTypedField('group', ChoiceType::class, ['label' => 'schedule.form.group', 'choices' => $options['groups'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'schedule.form.none']);
            $this->addTypedField('worker', ChoiceType::class, ['label' => 'schedule.form.worker', 'choices' => $options['workers'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'schedule.form.none']);
            $this->addTypedField('validFrom', DateType::class, ['label' => 'schedule.form.valid_from', 'widget' => 'single_text', 'input' => 'string']);
            $this->addTypedField('timezone', TimezoneType::class, ['label' => 'schedule.form.timezone']);
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
                $this->addTypedField($day, TextType::class, ['label' => 'schedule.day.' . $day, 'required' => false, 'help' => $day === 'mon' ? 'schedule.form.ranges_help' : false, 'attr' => ['maxlength' => 80, 'placeholder' => '09:00-14:00, 15:00-18:00']]);
            }
            $this->addTypedField('dailyTarget', IntegerType::class, ['label' => 'schedule.form.daily_target', 'required' => false, 'attr' => ['min' => 0, 'max' => 720]]);
            $this->addTypedField('weeklyTarget', IntegerType::class, ['label' => 'schedule.form.weekly_target', 'required' => false, 'attr' => ['min' => 0, 'max' => 5040]]);
            $this->addTypedField('flex', IntegerType::class, ['label' => 'schedule.form.flex', 'required' => false, 'attr' => ['min' => 0, 'max' => 240]]);
            if ($options['work_centres'] !== []) {
                $this->addTypedField('workCentre', ChoiceType::class, ['label' => 'schedule.form.work_centre', 'choices' => $options['work_centres'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'schedule.form.default_centre', 'help' => 'schedule.form.work_centre_help']);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_schedule`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_schedule',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
            'groups'             => [],
            'workers'            => [],
            'work_centres'       => [],
        ]);
    }

    /**
     * Field names `wt_schedule[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_schedule';
    }
}
