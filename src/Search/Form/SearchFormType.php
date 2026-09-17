<?php

declare(strict_types=1);

namespace App\Search\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SearchRequest> */
final class SearchFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('query', SearchType::class, ['required' => false, 'empty_data' => '', 'label' => 'search.form.search_form.query.label'])
            ->add('page', IntegerType::class, ['required' => false, 'empty_data' => '1']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SearchRequest::class, 'method' => 'GET', 'csrf_protection' => false]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
