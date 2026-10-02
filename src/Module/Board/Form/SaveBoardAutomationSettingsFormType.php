<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
        $builder->add('mergeStrategy', EnumType::class, [
            'class' => BoardMergeStrategy::class,
            'label' => 'board.form.save_board_automation_settings_form.merge_strategy.label',
            'help' => 'board.form.save_board_automation_settings_form.merge_strategy.help',
            'choice_label' => static fn (BoardMergeStrategy $strategy): string => 'board.form.save_board_automation_settings_form.merge_strategy.choice.'.$strategy->value,
        ]);
        $builder->add('fixStrategy', EnumType::class, [
            'class' => BoardFixStrategy::class,
            'label' => 'board.form.save_board_automation_settings_form.fix_strategy.label',
            'help' => 'board.form.save_board_automation_settings_form.fix_strategy.help',
            'choice_label' => static fn (BoardFixStrategy $strategy): string => 'board.form.save_board_automation_settings_form.fix_strategy.choice.'.$strategy->value,
        ]);
        $builder->add('loopLimit', IntegerType::class, [
            'label' => 'board.form.save_board_automation_settings_form.loop_limit.label',
            'help' => 'board.form.save_board_automation_settings_form.loop_limit.help',
            'attr' => ['min' => BoardAutomationSettings::MIN_LOOP_LIMIT, 'max' => BoardAutomationSettings::MAX_LOOP_LIMIT],
        ]);
        $builder->add('commentOnFixQueued', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.comment_on_fix_queued.label',
            'help' => 'board.form.save_board_automation_settings_form.comment_on_fix_queued.help',
        ]);
        $builder->add('commentOnStaleApproval', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.comment_on_stale_approval.label',
            'help' => 'board.form.save_board_automation_settings_form.comment_on_stale_approval.help',
        ]);
        $builder->add('syncBehind', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.sync_behind.label',
            'help' => 'board.form.save_board_automation_settings_form.sync_behind.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaveBoardAutomationSettingsRequest::class]);
    }
}
