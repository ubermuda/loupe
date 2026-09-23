<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CardLinkRowRequest> */
final class CardLinkRowType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $project = $options['project'] instanceof Project ? $options['project'] : throw new \LogicException('The resolver requires a project.');
        $card = $options['card'] instanceof Card ? $options['card'] : null;

        $builder
            ->add('card', CardLinkAutocompleteField::class, [
                'label' => 'board.form.card_link_row.card.label',
                'placeholder' => 'board.form.card_link_row.card.placeholder',
                'extra_options' => array_filter([
                    'projectId' => (string) $project->id,
                    'excludeCardId' => null === $card ? null : (string) $card->id,
                ]),
            ])
            ->add('kind', EnumType::class, [
                'class' => CardLinkKind::class,
                'label' => 'board.form.card_link_row.kind.label',
                'choice_label' => static fn (CardLinkKind $kind): string => 'board.form.card_link_row.kind.choice.'.$kind->value,
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CardLinkRowRequest::class, 'card' => null]);
        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', Project::class);
        $resolver->setAllowedTypes('card', ['null', Card::class]);
    }
}
