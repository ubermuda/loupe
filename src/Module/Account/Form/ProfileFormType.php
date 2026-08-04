<?php

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ProfileRequest> */
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'account.form.profile_form.full_name.label',
                'attr' => ['placeholder' => 'account.form.profile_form.full_name.placeholder', 'autocomplete' => 'name'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileRequest::class]);
    }
}
