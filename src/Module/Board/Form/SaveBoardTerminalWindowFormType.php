<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SaveBoardTerminalWindowRequest>
 */
final class SaveBoardTerminalWindowFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('terminalWindowDays', IntegerType::class, [
            'label' => 'board.form.save_board_terminal_window_form.terminal_window_days.label',
            'help' => 'board.form.save_board_terminal_window_form.terminal_window_days.help',
            'attr' => ['min' => BoardAutomationSettings::MIN_TERMINAL_WINDOW_DAYS, 'max' => BoardAutomationSettings::MAX_TERMINAL_WINDOW_DAYS],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaveBoardTerminalWindowRequest::class]);
    }
}
