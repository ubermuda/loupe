<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;

/** Dispatched after a bridge's rule report for the project commits. */
final readonly class BridgeRulesReported
{
    public function __construct(
        public Project $project,
    ) {
    }
}
