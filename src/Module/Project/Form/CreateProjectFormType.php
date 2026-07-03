<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use Symfony\Component\Form\AbstractType;
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
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateProjectRequest::class]);
    }
}
