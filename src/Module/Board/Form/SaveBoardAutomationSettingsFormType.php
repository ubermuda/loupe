<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SaveBoardAutomationSettingsRequest>
 */
final class SaveBoardAutomationSettingsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('enabled', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.enabled.label',
            'help' => 'board.form.save_board_automation_settings_form.enabled.help',
        ]);
        $builder->add('stuckDelayMinutes', IntegerType::class, [
            'label' => 'board.form.save_board_automation_settings_form.stuck_delay_minutes.label',
            'help' => 'board.form.save_board_automation_settings_form.stuck_delay_minutes.help',
            'attr' => ['min' => BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES, 'max' => BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaveBoardAutomationSettingsRequest::class]);
    }
}
