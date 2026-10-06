<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;

final readonly class ListWorkerRunReadingsCommand
{
    /** @param list<WorkerRun> $runs runs of the project */
    public function __construct(
        public Project $project,
        public array $runs,
    ) {
    }
}
