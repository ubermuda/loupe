<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Move to menu of one Backlog row. Each column of the menu is a submit
 * button that carries the column id, so the form renders no column field.
 *
 * @extends AbstractType<MoveBacklogCardRequest>
 */
final class MoveBacklogCardFormType extends AbstractType
{
    /** Both the Backlog page and the receiving controller build the form under this name. */
    public static function nameFor(Card $card): string
    {
        return 'move_backlog_card_'.($card->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('column', BoardColumnChoiceType::class, [
            'label' => false,
            'project' => $options['backlog']->project,
            'exclude' => $options['backlog'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MoveBacklogCardRequest::class]);
        $resolver->setRequired('backlog');
        $resolver->setAllowedTypes('backlog', BoardColumn::class);
    }
}
