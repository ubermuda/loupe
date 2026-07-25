<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DeleteProjectRequest>
 */
final class DeleteProjectFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirmName', TextType::class, [
            // Byte-for-byte confirmation: the widget must not trim the submitted
            // value, or a whitespace-padded name would wrongly pass the match.
            'trim' => false,
            'label' => 'project.form.delete_project_form.confirm_name.label',
            'attr' => ['autocomplete' => 'off'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeleteProjectRequest::class]);
    }
}
