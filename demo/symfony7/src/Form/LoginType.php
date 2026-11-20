<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Login form of the demo (FormKit profile `demo`, REQ-DEMO-015): field names match Symfony's form_login (`_username`,
 * `_password`), no prefix, no CSRF (the demo firewall turns it off; a real host keeps `enable_csrf: true`).
 *
 * @extends FormKitAbstractType<array<string, mixed>>
 */
#[FormKitConfig('demo')]
final class LoginType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, '_username', ['label' => 'Username', 'attr' => ['autocomplete' => 'username', 'autofocus' => true]]);
        $this->addPassword($builder, '_password', ['label' => 'Password', 'attr' => ['autocomplete' => 'current-password']]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'method' => 'POST']);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
