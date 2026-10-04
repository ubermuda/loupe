<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Doctrine\SearchLanguage;
use App\Module\Project\Service\WorkflowTemplateChoice;
use App\Module\Project\Service\WorkflowTemplateChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CreateProjectRequest> */
class CreateProjectFormType extends AbstractType
{
    public function __construct(
        private readonly WorkflowTemplateChoices $workflowTemplates,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'project.form.create_project_form.name.label',
                'attr' => ['placeholder' => 'project.form.create_project_form.name.placeholder'],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'project.form.create_project_form.description.label',
                'attr' => ['rows' => 3, 'maxlength' => 500],
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

        if (!$options['workflow_template']) {
            return;
        }

        $choices = $this->choicesByKey();
        $defaultKey = $this->workflowTemplates->defaultKey();
        $builder->add('workflowTemplate', ChoiceType::class, [
            'expanded' => true,
            'choices' => array_keys($choices),
            'choice_label' => static fn (string $key): string => $choices[$key]->label,
            'label' => 'project.form.create_project_form.workflow_template.label',
        ]);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (PreSetDataEvent $event) use ($defaultKey): void {
            $data = $event->getData();
            if ($data instanceof CreateProjectRequest && null === $data->workflowTemplate) {
                $data->workflowTemplate = $defaultKey;
            }
        });
    }

    #[\Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (!$options['workflow_template']) {
            return;
        }

        $choices = $this->choicesByKey();
        foreach ($view['workflowTemplate'] as $choiceView) {
            $choiceView->vars['description'] = $choices[$choiceView->vars['value']]->description;
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateProjectRequest::class,
            'workflow_template' => true,
            'validation_groups' => static fn (FormInterface $form): array => $form->getConfig()->getOption('workflow_template')
                ? ['Default', CreateProjectRequest::WITH_WORKFLOW_TEMPLATE]
                : ['Default'],
        ]);
        $resolver->setAllowedTypes('workflow_template', 'bool');
    }

    /** @return array<string, WorkflowTemplateChoice> */
    private function choicesByKey(): array
    {
        $choices = [];
        foreach ($this->workflowTemplates->choices() as $choice) {
            $choices[$choice->key] = $choice;
        }

        return $choices;
    }
}
