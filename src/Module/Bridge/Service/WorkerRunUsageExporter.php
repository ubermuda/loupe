<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;

/** A file of its own, because a usage row outlives the run that WorkerRunExporter writes. */
final readonly class WorkerRunUsageExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunUsageRepository $workerRunUsages,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_usage.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunUsages->findByOwner($user) as $usage) {
            yield [
                'project' => $usage->project->name,
                'cardId' => (string) $usage->cardId,
                'ruleName' => $usage->ruleName,
                'runKey' => $usage->run?->runKey?->toRfc4122(),
                'model' => $usage->model,
                'inputTokens' => $usage->inputTokens,
                'outputTokens' => $usage->outputTokens,
                'cacheReadTokens' => $usage->cacheReadTokens,
                'cacheWriteTokens' => $usage->cacheWriteTokens,
                'costUsd' => $usage->costUsd,
            ];
        }
    }
}
