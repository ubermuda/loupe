<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowWorkflowCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
