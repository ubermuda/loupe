<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ReviseDocumentRequest> */
final class ReviseDocumentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'review.form.revise_document_form.title.label'])
            ->add('markdown', TextareaType::class, ['label' => 'review.form.revise_document_form.markdown.label'])
            ->add('description', TextType::class, ['label' => 'review.form.revise_document_form.description.label'])
            ->add('versionNumber', IntegerType::class, ['label' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReviseDocumentRequest::class]);
    }
}
