<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use Symfony\Component\Uid\Uuid;

/** Loads the usage rows and the fact rows of some runs, with one query each. */
final readonly class ListWorkerRunReadingsHandler
{
    public function __construct(
        private WorkerRunUsageRepository $workerRunUsages,
        private WorkerRunFactRepository $workerRunFacts,
    ) {
    }

    public function __invoke(ListWorkerRunReadingsCommand $command): ListWorkerRunReadingsView
    {
        $ids = array_values(array_filter(array_map(static fn (WorkerRun $run): ?Uuid => $run->id, $command->runs)));

        return new ListWorkerRunReadingsView(
            $this->workerRunUsages->findByRunIds($ids),
            $this->workerRunFacts->findByRunIds($command->project, $ids),
        );
    }
}
