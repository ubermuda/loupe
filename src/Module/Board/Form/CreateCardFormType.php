<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
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
        $project = $options['project'] instanceof Project ? $options['project'] : throw new \LogicException('The resolver requires a project.');
        $card = $options['card'] instanceof Card ? $options['card'] : null;

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
            ->add('parent', CardParentAutocompleteField::class, [
                'required' => false,
                'label' => 'board.form.create_card_form.parent.label',
                'placeholder' => 'board.form.create_card_form.parent.placeholder',
                'help' => 'board.form.create_card_form.parent.help',
                'help_attr' => ['class' => 'lp-form-hint'],
                'extra_options' => array_filter([
                    'projectId' => (string) $project->id,
                    'excludeCardId' => null === $card ? null : (string) $card->id,
                ]),
            ])
            ->add('pullRequestUrls', TextareaType::class, [
                'required' => false,
                'label' => 'board.form.create_card_form.pull_request_urls.label',
                'help' => 'board.form.create_card_form.pull_request_urls.help',
                'help_attr' => ['class' => 'lp-form-hint'],
                'attr' => ['rows' => 4, 'placeholder' => 'board.form.create_card_form.pull_request_urls.placeholder'],
            ])
            // The whole set: a row missing from the submit is a link removed.
            ->add('relatedCards', CollectionType::class, [
                'required' => false,
                'label' => 'board.form.create_card_form.related_cards.label',
                'help' => 'board.form.create_card_form.related_cards.help',
                'help_attr' => ['class' => 'lp-form-hint'],
                'entry_type' => CardLinkRowType::class,
                'entry_options' => ['project' => $options['project'], 'card' => $options['card']],
                'allow_add' => true,
                'allow_delete' => true,
                // A domain error on the set shows under the set, not at the top of the form.
                'error_bubbling' => false,
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateCardRequest::class,
            // The card being edited, which its own link rows leave out.
            'card' => null,
        ]);
        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', Project::class);
        $resolver->setAllowedTypes('card', ['null', Card::class]);
    }
}
