<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<LinkSocialAccountRequest> */
class LinkSocialAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'account.form.link_social_account_form.password.label',
            'attr' => [
                'placeholder' => 'account.form.link_social_account_form.password.placeholder',
                'autocomplete' => 'current-password',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LinkSocialAccountRequest::class,
        ]);
    }
}
