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
        $builder->add('target', BoardColumnChoiceType::class, [
            'label' => 'board.form.delete_board_column_form.target.label',
            'project' => $options['column']->project,
            'exclude' => $options['column'],
            'required' => false,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeleteBoardColumnRequest::class]);
        $resolver->setRequired('column');
        $resolver->setAllowedTypes('column', BoardColumn::class);
    }
}
