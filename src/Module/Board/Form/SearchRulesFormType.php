<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SearchRulesRequest> */
final class SearchRulesFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('search', SearchType::class, [
            'label' => 'board.form.search_rules_form.search.label',
            'required' => false,
            'attr' => ['placeholder' => 'board.form.search_rules_form.search.placeholder'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SearchRulesRequest::class,
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
