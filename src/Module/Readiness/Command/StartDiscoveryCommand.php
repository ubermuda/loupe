<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Board\Entity\CardReporter;
use App\Module\Project\Entity\Project;

final readonly class StartDiscoveryCommand
{
    public function __construct(
        public Project $project,
        /** Human from the web page, Agent from the MCP tool. */
        public CardReporter $reporter,
    ) {
    }
}
