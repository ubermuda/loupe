<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One per column, so it is created under a per-column name. The target picker
 * lists the board's other columns only.
 *
 * @extends AbstractType<DeleteBoardColumnRequest>
 */
final class DeleteBoardColumnFormType extends AbstractType
{
    /** Both the board and the receiving controller build the form under this name. */
    public static function nameFor(BoardColumn $column): string
    {
        return 'delete_board_column_'.($column->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $column = $options['column'];
        $target = [
            'label' => 'board.form.delete_board_column_form.target.label',
            'project' => $column->project,
            'exclude' => $column,
            'required' => false,
        ];
        // The board passes the columns it already holds, so rendering a form per
        // column costs no query. The receiving controller passes none, and the
        // choices come from the database.
        if (null !== $options['columns']) {
            $target['choices'] = array_values(array_filter(
                $options['columns'],
                static fn (BoardColumn $other): bool => $other !== $column,
            ));
        }

        $builder->add('target', BoardColumnChoiceType::class, $target);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeleteBoardColumnRequest::class, 'columns' => null]);
        $resolver->setRequired('column');
        $resolver->setAllowedTypes('column', BoardColumn::class);
        $resolver->setAllowedTypes('columns', ['null', BoardColumn::class.'[]']);
    }
}
