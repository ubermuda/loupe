<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One per column, so it is created under a per-column name. The hidden fields
 * carry what the dialog showed, so a save cannot undo a newer edit.
 *
 * @extends AbstractType<ConfigureBoardColumnRequest>
 */
final class ConfigureBoardColumnFormType extends AbstractType
{
    /** Both the settings page and the receiving controller build the form under this name. */
    public static function nameFor(BoardColumn $column): string
    {
        return 'configure_board_column_'.($column->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'board.form.configure_board_column_form.label.label',
        ]);
        $builder->add('isDefault', CheckboxType::class, [
            'label' => 'board.form.configure_board_column_form.is_default.label',
            'required' => false,
        ]);
        $builder->add('terminal', CheckboxType::class, [
            'label' => 'board.form.configure_board_column_form.terminal.label',
            'required' => false,
        ]);
        $builder->add('tone', BoardColumnToneType::class, [
            'label' => 'board.form.configure_board_column_form.tone.label',
        ]);
        $builder->add('expectedLabel', HiddenType::class);
        $builder->add('expectedDefaultId', HiddenType::class);
        $builder->add('expectedTerminal', HiddenType::class);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConfigureBoardColumnRequest::class]);
    }
}
