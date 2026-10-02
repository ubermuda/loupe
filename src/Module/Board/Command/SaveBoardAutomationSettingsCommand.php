<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Project\Entity\Project;

final readonly class SaveBoardAutomationSettingsCommand
{
    public function __construct(
        public Project $project,
        public bool $enabled,
        public BoardMergeStrategy $mergeStrategy,
        public BoardFixStrategy $fixStrategy,
        public int $loopLimit,
        public bool $commentOnFixQueued,
        public bool $commentOnStaleApproval,
        public bool $syncBehind,
        public bool $mergePullRequests,
        public bool $changeBase,
    ) {
    }
}
