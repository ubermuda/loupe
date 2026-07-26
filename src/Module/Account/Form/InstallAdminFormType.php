<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<InstallAdminRequest> */
final class InstallAdminFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'account.form.install_admin_form.full_name.label',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('username', TextType::class, [
                'label' => 'account.form.install_admin_form.username.label',
                'attr' => ['autocomplete' => 'username'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'account.form.install_admin_form.email.label',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'account.form.install_admin_form.password.label',
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InstallAdminRequest::class]);
    }
}
