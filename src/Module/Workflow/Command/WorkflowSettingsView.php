<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Project\Entity\Project;

final readonly class WorkflowSettingsView
{
    /** @param ?BoundWorkflowView $template null when the project is bound to no template */
    public function __construct(
        public Project $project,
        public ?BoundWorkflowView $template,
    ) {
    }
}
