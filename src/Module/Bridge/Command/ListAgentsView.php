<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\View\AgentConnection;
use App\Module\Project\Entity\Project;

final readonly class ListAgentsView
{
    /** @param list<AgentConnection> $connections */
    public function __construct(
        public Project $project,
        public array $connections,
    ) {
    }
}
