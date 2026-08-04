<?php

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<RegistrationRequest> */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'account.form.registration_form.email.label',
                'attr' => ['placeholder' => 'account.form.registration_form.email.placeholder', 'autocomplete' => 'email'],
            ])
            ->add('fullName', TextType::class, [
                'label' => 'account.form.registration_form.full_name.label',
                'attr' => ['placeholder' => 'account.form.registration_form.full_name.placeholder', 'autocomplete' => 'name'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'account.form.registration_form.password.label',
                'attr' => ['placeholder' => 'account.form.registration_form.password.placeholder', 'autocomplete' => 'new-password'],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'account.form.registration_form.agree_terms.label',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegistrationRequest::class]);
    }
}
