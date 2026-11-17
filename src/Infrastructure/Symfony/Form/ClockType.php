<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A clock action (one per button): the idempotency key of this rendering (a double click sends the same key).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class ClockType extends AbstractType
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
            $this->addTypedField('requestKey', HiddenType::class, ['label' => false]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_clock`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_clock',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `wt_clock[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_clock';
    }
}
