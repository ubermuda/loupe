<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SetCardLaneRequest> */
final class SetCardLaneFormType extends AbstractType
{
    public const string PREFIX = 'set_card_lane_';

    public static function nameFor(Card $card): string
    {
        return self::PREFIX.$card->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('laneEnabled', HiddenType::class)
            ->add('returnTo', HiddenType::class);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SetCardLaneRequest::class]);
    }
}
