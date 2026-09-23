<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Project\Entity\Project;

final readonly class CreateGitHubHookCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
