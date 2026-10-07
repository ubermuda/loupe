<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;

/** Dispatched after the automation settings of the project's board are written. */
final readonly class BoardAutomationSettingsSaved
{
    public function __construct(
        public Project $project,
        /** True when this save switched the automation on. */
        public bool $turnedOn = false,
        /** True when this save switched the opening of epic pull requests on. */
        public bool $openEpicTurnedOn = false,
    ) {
    }
}
