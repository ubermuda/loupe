<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateInboxSettingsCommand
{
    public function __construct(
        public Project $project,
        public bool $documentInReview,
        public bool $runBlocked,
        public bool $runGaveUp,
        public bool $runWaitingForPerson,
        public bool $pullRequestReady,
        public bool $pullRequestFixStopped,
        public bool $cardPaused,
    ) {
    }
}
