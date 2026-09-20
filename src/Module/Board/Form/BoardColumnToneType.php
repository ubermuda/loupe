<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\LabelTone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The colour of a board column, as a radio group of the label palette. The
 * board settings render each choice as a swatch.
 *
 * @extends AbstractType<LabelTone>
 */
final class BoardColumnToneType extends AbstractType
{
    #[\Override]
    public function getParent(): string
    {
        return EnumType::class;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => LabelTone::class,
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
            'choice_label' => static fn (LabelTone $tone): string => 'board.form.board_column_tone.choice.'.$tone->value,
        ]);
    }
}
