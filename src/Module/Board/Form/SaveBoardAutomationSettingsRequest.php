<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;
use Symfony\Component\Validator\Constraints as Assert;

class SaveBoardAutomationSettingsRequest
{
    public function __construct(
        public bool $enabled = true,
        public bool $commentOnFixQueued = false,
        public bool $commentOnStaleApproval = false,
        public bool $syncBehind = false,
        public bool $mergePullRequests = false,
        public bool $changeBase = false,
        public bool $epicDraftSwitch = false,
        public bool $closeEpicPullRequests = false,
        public bool $openEpicPullRequests = false,
        public bool $postWidgetReviews = false,
        public bool $siteReviewCheck = false,
        public bool $agentReview = false,

        /** @var list<string> */
        #[Assert\Count(min: 1, minMessage: 'board.form.save_board_automation_settings_form.agent_review_failing_severities.none')]
        public array $agentReviewFailingSeverities = BoardAutomationSettings::DEFAULT_AGENT_REVIEW_FAILING_SEVERITIES,

        /** Blank, or a Git branch name that holds the epic number placeholder exactly once. */
        #[Assert\Length(max: BoardAutomationSettings::EPIC_BRANCH_PATTERN_MAX_LENGTH)]
        #[Assert\Regex(pattern: BoardAutomationSettings::EPIC_BRANCH_PATTERN_RULE, message: 'board.form.save_board_automation_settings_form.epic_branch_pattern.invalid')]
        public ?string $epicBranchPattern = BoardAutomationSettings::DEFAULT_EPIC_BRANCH_PATTERN,
    ) {
    }

    public static function fromSettings(BoardAutomationSettings $settings): self
    {
        return new self($settings->enabled, $settings->commentOnFixQueued, $settings->commentOnStaleApproval, $settings->syncBehind, $settings->mergePullRequests, $settings->changeBase, $settings->epicDraftSwitch, $settings->closeEpicPullRequests, $settings->openEpicPullRequests, $settings->postWidgetReviews, $settings->siteReviewCheck, $settings->agentReview, $settings->agentReviewFailingSeverities, $settings->epicBranchPattern);
    }
}
