<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The board's move form. One per card, so it is created under a per-card name.
 *
 * The column select is what a keyboard reaches, and it is also the field the
 * drag controller writes before it submits the form. The rank is hidden,
 * because a reader picks a column rather than a number.
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
            ->add('column', BoardColumnChoiceType::class, [
                'label' => 'board.form.move_card_form.column.label',
                'project' => $options['project'],
            ])
            // An integer field rather than a hidden one: the property is ?int,
            // and HiddenType would hand the property mapper a string.
            ->add('position', IntegerType::class, ['required' => false])
            // A drop inside a lane fills these instead of the rank.
            ->add('parent', HiddenType::class, ['required' => false])
            ->add('beforeCardId', HiddenType::class, ['required' => false])
            ->add('afterCardId', HiddenType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MoveCardRequest::class]);
        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', Project::class);
    }
}
