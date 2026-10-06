<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;

/** The engine runs the workflow of a project while its automation is on for the project. */
final readonly class WorkflowAutomation
{
    public function __construct(
        private BoardAutomation $boardAutomation,
    ) {
    }

    public function runsFor(Project $project): bool
    {
        return $this->boardAutomation->settingsOf($project)->enabled;
    }
}
