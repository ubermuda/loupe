<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\ApiTokenScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<MintApiTokenRequest> */
class MintApiTokenFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'account.form.mint_api_token_form.label.label',
                'attr' => ['placeholder' => 'account.form.mint_api_token_form.label.placeholder'],
            ])
            ->add('scope', EnumType::class, [
                'class' => ApiTokenScope::class,
                'label' => 'account.form.mint_api_token_form.scope.label',
                'help' => 'account.form.mint_api_token_form.scope.help',
                'help_attr' => ['class' => 'lp-form-hint'],
                'choice_label' => static fn (ApiTokenScope $scope): string => 'account.form.mint_api_token_form.scope.choice.'.str_replace('-', '_', $scope->value),
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MintApiTokenRequest::class]);
    }
}
