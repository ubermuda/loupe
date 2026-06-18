<?php

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'account.form.change_password_form.new_password.label',
                'attr' => ['placeholder' => 'account.form.change_password_form.new_password.placeholder', 'autocomplete' => 'new-password'],
                'constraints' => [new NotBlank(), new Length(min: 8)],
            ],
            'second_options' => [
                'label' => 'account.form.change_password_form.repeat_new_password.label',
                'attr' => ['placeholder' => 'account.form.change_password_form.repeat_new_password.placeholder', 'autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'account.form.change_password_form.invalid_message',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
