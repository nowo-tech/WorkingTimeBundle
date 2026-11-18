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
 * Confirms a file preview: the new rows travel back as JSON and are checked again on the server.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoWorkingTimeBundle::FORM_PROFILE)]
final class HolidayImportConfirmType extends AbstractType
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
            $this->addTypedField('rows', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_holiday_import_confirm`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_holiday_import_confirm',
            'allow_extra_fields' => false,
            'translation_domain' => NowoWorkingTimeBundle::TRANSLATION_DOMAIN,

        ]);
    }

    /**
     * Field names `wt_holiday_import_confirm[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_holiday_import_confirm';
    }
}
