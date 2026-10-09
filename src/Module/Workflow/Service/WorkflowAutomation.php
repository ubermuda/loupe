<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BoardSettings;

/** The engine runs the workflow of a project while its automation is on for the project. */
final readonly class WorkflowAutomation
{
    public function __construct(
        private BoardSettings $boardSettings,
    ) {
    }

    public function runsFor(Project $project): bool
    {
        return $this->boardSettings->automationEnabled($project->id ?? throw new \LogicException('A stored project has an id.'));
    }
}
