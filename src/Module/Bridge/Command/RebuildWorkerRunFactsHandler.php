<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunFactWriter;

/**
 * Upserts the fact row of each run in id order, and answers how many runs it
 * wrote. A fact row whose run is gone stays.
 */
final readonly class RebuildWorkerRunFactsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunFactWriter $writer,
    ) {
    }

    public function __invoke(RebuildWorkerRunFactsCommand $command): int
    {
        $written = 0;
        $after = null;
        do {
            $ids = $this->workerRuns->findIdsAfter($after, $command->batchSize);
            $this->writer->upsert($ids);
            $written += \count($ids);
            $after = array_last($ids);
        } while (\count($ids) === $command->batchSize);

        return $written;
    }
}
