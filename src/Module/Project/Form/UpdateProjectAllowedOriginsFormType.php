<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<UpdateProjectAllowedOriginsRequest> */
class UpdateProjectAllowedOriginsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('origins', TextareaType::class, [
            'label' => 'project.form.update_project_allowed_origins_form.origins.label',
            'help' => 'project.form.update_project_allowed_origins_form.origins.help',
            'required' => false,
            'attr' => [
                'rows' => 3,
                'placeholder' => 'project.form.update_project_allowed_origins_form.origins.placeholder',
                'spellcheck' => 'false',
            ],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => UpdateProjectAllowedOriginsRequest::class]);
    }
}
