<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Upserts the fact row of each run in id order, and answers how many runs it
 * wrote. A fact row whose run is gone stays.
 */
final readonly class RebuildWorkerRunFactsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunFactWriter $writer,
        private Connection $connection,
    ) {
    }

    public function __invoke(RebuildWorkerRunFactsCommand $command): int
    {
        $written = 0;
        $after = null;
        do {
            $ids = $this->workerRuns->findIdsAfter($after, $command->batchSize);
            // A run writer locks the project before the run, so the rebuild does too.
            $this->connection->transactional(function () use ($ids): void {
                $this->connection->executeQuery(
                    'SELECT id FROM projects WHERE id IN (SELECT project_id FROM bridge_worker_runs WHERE id IN (:ids)) ORDER BY id FOR KEY SHARE',
                    ['ids' => array_map(strval(...), $ids)],
                    ['ids' => ArrayParameterType::STRING],
                );
                $this->writer->upsert($ids);
            });
            $written += \count($ids);
            $after = array_last($ids);
        } while (\count($ids) === $command->batchSize);

        return $written;
    }
}
