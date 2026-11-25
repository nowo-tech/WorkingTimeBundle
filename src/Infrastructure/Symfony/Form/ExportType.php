<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An export request (spec 198 FR-021 / FR-022): the own copy, one worker's inspection export or the team's ZIP; first and last month (`Y-m`, at most 12) and the format.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class ExportType extends AbstractType
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
            $scopes = ['export.scope.own' => 'own'];
            if ($options['inspection']) {
                $scopes += ['export.scope.worker' => 'worker', 'export.scope.team' => 'team'];
            }
            $this->addTypedField('scope', ChoiceType::class, ['label' => 'export.form.scope', 'choices' => $scopes]);
            if ($options['inspection']) {
                $this->addTypedField('worker', ChoiceType::class, ['label' => 'export.form.worker', 'choices' => $options['workers'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => 'export.form.none']);
            }
            $this->addTypedField('from', TextType::class, ['label' => 'export.form.from', 'attr' => ['placeholder' => 'YYYY-MM', 'pattern' => '\\d{4}-\\d{2}', 'maxlength' => 7]]);
            $this->addTypedField('to', TextType::class, ['label' => 'export.form.to', 'attr' => ['placeholder' => 'YYYY-MM', 'pattern' => '\\d{4}-\\d{2}', 'maxlength' => 7]]);
            $this->addTypedField('format', ChoiceType::class, ['label' => 'export.form.format', 'choices' => $options['formats']]);
        });
    }

    /**
     * Array data, CSRF token `nowo_working_time_export`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_working_time_export',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'inspection'         => false,
            'workers'            => [],
            'formats'            => [],
        ]);
    }

    /**
     * Field names `wt_export[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'wt_export';
    }
}
