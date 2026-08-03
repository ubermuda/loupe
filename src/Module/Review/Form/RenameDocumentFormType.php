<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<RenameDocumentRequest> */
class RenameDocumentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, [
            'label' => 'review.form.rename_document_form.title.label',
            'attr' => ['placeholder' => 'review.form.rename_document_form.title.placeholder'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RenameDocumentRequest::class]);
    }
}
