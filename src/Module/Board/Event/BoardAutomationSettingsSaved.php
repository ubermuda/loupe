<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;

/** Dispatched after the automation settings of the project's board are written. */
final readonly class BoardAutomationSettingsSaved
{
    public function __construct(
        public Project $project,
    ) {
    }
}
