<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

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
        public bool $epicDraftSwitch = false,
        public bool $closeEpicPullRequests = false,
    ) {
    }
}
