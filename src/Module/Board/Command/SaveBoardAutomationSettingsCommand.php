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
        public int $stuckDelayMinutes = BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES,
    ) {
    }
}
