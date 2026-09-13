<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One per column, so it is created under a per-column name.
 *
 * @extends AbstractType<RenameBoardColumnRequest>
 */
final class RenameBoardColumnFormType extends AbstractType
{
    /** Both the board and the receiving controller build the form under this name. */
    public static function nameFor(BoardColumn $column): string
    {
        return 'rename_board_column_'.($column->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'board.form.rename_board_column_form.label.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RenameBoardColumnRequest::class]);
    }
}
