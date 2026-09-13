<?php

declare(strict_types=1);

namespace App\Mercure\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AuthorizeMercureTopicsRequest>
 */
final class AuthorizeMercureTopicsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('topics', CollectionType::class, [
            'entry_type' => TextType::class,
            'allow_add' => true,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AuthorizeMercureTopicsRequest::class]);
    }
}
