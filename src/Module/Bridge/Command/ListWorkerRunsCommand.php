<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;

final readonly class ListWorkerRunsCommand
{
    public function __construct(
        public Project $project,
        public WorkerRunListQuery $listQuery,
        public int $perPage = ListWorkerRunsHandler::PER_PAGE,
    ) {
    }
}
