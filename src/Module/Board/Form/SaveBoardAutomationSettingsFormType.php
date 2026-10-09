<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
        $builder->add('mergePullRequests', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.merge_pull_requests.label',
            'help' => 'board.form.save_board_automation_settings_form.merge_pull_requests.help',
        ]);
        $builder->add('changeBase', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.change_base.label',
            'help' => 'board.form.save_board_automation_settings_form.change_base.help',
        ]);
        $builder->add('epicDraftSwitch', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.epic_draft_switch.label',
            'help' => 'board.form.save_board_automation_settings_form.epic_draft_switch.help',
        ]);
        $builder->add('closeEpicPullRequests', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.close_epic_pull_requests.label',
            'help' => 'board.form.save_board_automation_settings_form.close_epic_pull_requests.help',
        ]);
        $builder->add('openEpicPullRequests', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.open_epic_pull_requests.label',
            'help' => 'board.form.save_board_automation_settings_form.open_epic_pull_requests.help',
        ]);
        $builder->add('postWidgetReviews', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.post_widget_reviews.label',
            'help' => 'board.form.save_board_automation_settings_form.post_widget_reviews.help',
        ]);
        $builder->add('siteReviewCheck', CheckboxType::class, [
            'required' => false,
            'label' => 'board.form.save_board_automation_settings_form.site_review_check.label',
            'help' => 'board.form.save_board_automation_settings_form.site_review_check.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaveBoardAutomationSettingsRequest::class]);
    }
}
