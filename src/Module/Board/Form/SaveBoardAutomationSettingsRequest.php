<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardAutomationSettings;

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
    ) {
    }

    public static function fromSettings(BoardAutomationSettings $settings): self
    {
        return new self($settings->enabled, $settings->commentOnFixQueued, $settings->commentOnStaleApproval, $settings->syncBehind, $settings->mergePullRequests, $settings->changeBase, $settings->epicDraftSwitch, $settings->closeEpicPullRequests, $settings->openEpicPullRequests, $settings->postWidgetReviews, $settings->siteReviewCheck);
    }
}
