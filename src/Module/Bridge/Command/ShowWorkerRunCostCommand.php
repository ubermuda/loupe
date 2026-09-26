<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\View\WorkerRunCostQuery;
use App\Module\Project\Entity\Project;

final readonly class ShowWorkerRunCostCommand
{
    public function __construct(
        public Project $project,
        public WorkerRunCostQuery $query,
    ) {
    }
}
