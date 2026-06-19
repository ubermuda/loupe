<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\ApiTokenScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiTokenRequest> */
class ApiTokenFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'account.form.api_token_form.label.label',
            'attr' => ['placeholder' => 'account.form.api_token_form.label.placeholder'],
        ]);

        $builder->add('scope', EnumType::class, [
            'class' => ApiTokenScope::class,
            'label' => 'account.form.api_token_form.scope.label',
            'choice_label' => fn (ApiTokenScope $scope) => 'account.form.api_token_form.scope.'.$scope->value,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiTokenRequest::class]);
    }
}
