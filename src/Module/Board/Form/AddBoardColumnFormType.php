<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AddBoardColumnRequest>
 */
final class AddBoardColumnFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'board.form.add_board_column_form.label.label',
        ]);
        $builder->add('tone', BoardColumnToneType::class, [
            'label' => 'board.form.add_board_column_form.tone.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddBoardColumnRequest::class]);
    }
}
