<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CreateCardRequest> */
class CreateCardFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'board.form.create_card_form.title.label',
                'attr' => ['placeholder' => 'board.form.create_card_form.title.placeholder'],
            ])
            ->add('body', TextareaType::class, [
                'required' => false,
                'label' => 'board.form.create_card_form.body.label',
                'help' => 'board.form.create_card_form.body.help',
                'help_attr' => ['class' => 'lp-form-hint'],
                'attr' => ['rows' => 10],
            ])
            ->add('type', EnumType::class, [
                'class' => CardType::class,
                'label' => 'board.form.create_card_form.type.label',
                'choice_label' => static fn (CardType $type): string => 'board.form.create_card_form.type.choice.'.$type->value,
            ])
            ->add('column', BoardColumnChoiceType::class, [
                'label' => 'board.form.create_card_form.column.label',
                'project' => $options['project'],
            ])
            ->add('pullRequestUrls', TextareaType::class, [
                'required' => false,
                'label' => 'board.form.create_card_form.pull_request_urls.label',
                'help' => 'board.form.create_card_form.pull_request_urls.help',
                'help_attr' => ['class' => 'lp-form-hint'],
                'attr' => ['rows' => 4, 'placeholder' => 'board.form.create_card_form.pull_request_urls.placeholder'],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateCardRequest::class]);
        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', Project::class);
    }
}
