<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The hidden form of one Backlog row that the rank drag fills and submits.
 *
 * @extends AbstractType<RankBacklogCardRequest>
 */
final class RankBacklogCardFormType extends AbstractType
{
    /** Both the Backlog page and the receiving controller build the form under this name. */
    public static function nameFor(Card $card): string
    {
        return 'rank_backlog_card_'.($card->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('beforeCardId', HiddenType::class, ['required' => false])
            ->add('afterCardId', HiddenType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RankBacklogCardRequest::class]);
    }
}
