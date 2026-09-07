<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The board's move form. One per card, so it is created under a per-card name.
 *
 * The two selects are what a keyboard reaches, and they are also the fields the
 * drag controller writes before it submits the form. The rank is hidden,
 * because a reader picks a column and a grade rather than a number.
 *
 * @extends AbstractType<MoveCardRequest>
 */
final class MoveCardFormType extends AbstractType
{
    /** Both the board and the receiving controller build the form under this name. */
    public static function nameFor(Card $card): string
    {
        return 'move_card_'.($card->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('status', EnumType::class, [
                'class' => CardStatus::class,
                'label' => 'board.form.move_card_form.status.label',
                'choice_label' => static fn (CardStatus $status): string => 'board.form.move_card_form.status.choice.'.$status->value,
            ])
            ->add('priority', EnumType::class, [
                'class' => CardPriority::class,
                'label' => 'board.form.move_card_form.priority.label',
                'choice_label' => static fn (CardPriority $priority): string => 'board.form.move_card_form.priority.choice.'.$priority->label(),
            ])
            // An integer field rather than a hidden one: the property is ?int,
            // and HiddenType would hand the property mapper a string.
            ->add('position', IntegerType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MoveCardRequest::class]);
    }
}
