<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
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
            ->add('priority', EnumType::class, [
                'class' => CardPriority::class,
                'label' => 'board.form.create_card_form.priority.label',
                'choice_label' => static fn (CardPriority $priority): string => 'board.form.create_card_form.priority.choice.'.$priority->label(),
            ])
            ->add('status', EnumType::class, [
                'class' => CardStatus::class,
                'label' => 'board.form.create_card_form.status.label',
                'choice_label' => static fn (CardStatus $status): string => 'board.form.create_card_form.status.choice.'.$status->value,
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
    }
}
