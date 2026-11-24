<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A CSV (date,name,scope) or ICS file to preview against a calendar.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class HolidayImportType extends AbstractType
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
            $this->addTypedField('file', FileType::class, ['label' => 'holiday.import.form.file', 'help' => 'holiday.import.form.file_help', 'attr' => ['accept' => '.csv,.ics,text/csv,text/calendar']]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_holiday_import`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_holiday_import',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,

        ]);
    }

    /**
     * Field names `wt_holiday_import[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_holiday_import';
    }
}
