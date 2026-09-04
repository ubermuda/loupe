<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Doctrine\SearchLanguage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CreateProjectRequest> */
class CreateProjectFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'project.form.create_project_form.name.label',
                'attr' => ['placeholder' => 'project.form.create_project_form.name.placeholder'],
            ])
            ->add('domain', TextType::class, [
                'required' => false,
                'label' => 'project.form.create_project_form.domain.label',
                'attr' => ['placeholder' => 'project.form.create_project_form.domain.placeholder'],
            ])
            ->add('searchLanguage', EnumType::class, [
                'class' => SearchLanguage::class,
                // Simple is not a language, so it goes last rather than between
                // Serbian and Spanish where its backing value sorts it.
                'choices' => [
                    ...array_values(array_filter(
                        SearchLanguage::cases(),
                        static fn (SearchLanguage $language): bool => SearchLanguage::Simple !== $language,
                    )),
                    SearchLanguage::Simple,
                ],
                'label' => 'project.form.create_project_form.search_language.label',
                'choice_label' => static fn (SearchLanguage $language): string => 'project.form.create_project_form.search_language.choice.'.$language->value,
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateProjectRequest::class]);
    }
}
