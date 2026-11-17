<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Withdraws a schedule row that is not valid yet (CSRF only).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class WithdrawType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, static function (): void {
            // CSRF only.
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_withdraw`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_withdraw',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `wt_withdraw[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_withdraw';
    }
}
