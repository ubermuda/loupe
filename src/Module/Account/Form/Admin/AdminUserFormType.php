<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AdminUserRequest> */
final class AdminUserFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'account.form.admin_user_form.full_name.label',
            ])
            ->add('email', EmailType::class, [
                'label' => 'account.form.admin_user_form.email.label',
                'help' => 'account.form.admin_user_form.email.help',
            ])
            ->add('isAdmin', CheckboxType::class, [
                'required' => false,
                'label' => 'account.form.admin_user_form.is_admin.label',
            ])
            ->add('isVerified', CheckboxType::class, [
                'required' => false,
                'label' => 'account.form.admin_user_form.is_verified.label',
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AdminUserRequest::class]);
    }
}
