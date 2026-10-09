<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;

final readonly class SaveBoardAutomationSettingsCommand
{
    public function __construct(
        public Project $project,
        public bool $enabled,
        public bool $commentOnFixQueued,
        public bool $commentOnStaleApproval,
        public bool $syncBehind,
        public bool $mergePullRequests,
        public bool $changeBase,
        public bool $postWidgetReviews,
        public bool $siteReviewCheck,
        public bool $epicDraftSwitch = false,
        public bool $closeEpicPullRequests = false,
        public bool $openEpicPullRequests = false,
        public ?string $epicBranchPattern = BoardAutomationSettings::DEFAULT_EPIC_BRANCH_PATTERN,
    ) {
    }
}
