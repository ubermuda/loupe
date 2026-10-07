<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;

final readonly class RecordAgentGitHubLoginCommand
{
    public function __construct(
        public Project $project,
        /** Null or '' clears the recorded login. */
        public ?string $login,
    ) {
    }
}
