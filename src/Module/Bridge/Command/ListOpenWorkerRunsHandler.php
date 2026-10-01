<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;

final readonly class ListOpenWorkerRunsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(ListOpenWorkerRunsCommand $command): ListOpenWorkerRunsView
    {
        return new ListOpenWorkerRunsView($this->workerRuns->findOpenOfProject($command->project));
    }
}
