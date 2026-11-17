<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Login form of the demo: field names match Symfony's form_login (`_username`, `_password`), no prefix, no CSRF
 * (the demo firewall turns it off; a real host keeps `enable_csrf: true`).
 */
final class LoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', TextType::class, ['label' => 'Username', 'attr' => ['autocomplete' => 'username', 'autofocus' => true]])
            ->add('_password', PasswordType::class, ['label' => 'Password', 'attr' => ['autocomplete' => 'current-password']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'method' => 'POST']);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
