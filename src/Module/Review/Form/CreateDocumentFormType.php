<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentWorkLinksInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CreateDocumentRequest> */
final class CreateDocumentFormType extends AbstractType
{
    public function __construct(
        private readonly DocumentWorkLinksInterface $workLinks,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'review.form.create_document_form.title.label'])
            ->add('markdown', TextareaType::class, ['label' => 'review.form.create_document_form.markdown.label']);
        if ($this->workLinks->isEnabled()) {
            $builder->add('workLinkIds', ChoiceType::class, [
                'label' => 'review.form.create_document_form.work_links.label',
                'help' => 'review.form.create_document_form.work_links.help',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choice_translation_domain' => false,
                'choices' => $this->workLinks->choices($options['project'], $options['document']),
                'data' => null === $options['document'] ? [] : $this->workLinks->selectedIds($options['document']),
            ]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateDocumentRequest::class, 'document' => null]);
        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', Project::class);
        $resolver->setAllowedTypes('document', ['null', Document::class]);
    }
}
