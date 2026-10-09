<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;

final readonly class StartDiscoveryCommand
{
    public function __construct(
        public Project $project,
        /** Human from the web page, Agent from the MCP tool. */
        public Actor $reporter,
    ) {
    }
}
