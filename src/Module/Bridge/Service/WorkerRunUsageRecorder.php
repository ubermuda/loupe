<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the usage of a run when the report is better than what the run holds.
 * The caller holds the project lock and flushes.
 */
final readonly class WorkerRunUsageRecorder
{
    public function __construct(
        private WorkerRunUsageRepository $usage,
        private EntityManagerInterface $em,
    ) {
    }

    /** Answers whether the usage of the run changed. */
    public function record(WorkerRun $run, WorkerRunUsageReport $report): bool
    {
        if (!$report->replaces($run->usageSource)) {
            return false;
        }

        if (null !== $run->usageSource) {
            $this->usage->deleteForRun($run);
        }

        $run->usageSource = $report->source;
        foreach ($report->models as $model) {
            $this->em->persist(new WorkerRunUsage(
                run: $run,
                project: $run->project,
                cardId: $run->cardId,
                ruleName: $run->ruleName,
                model: $model->model,
                inputTokens: $model->inputTokens,
                outputTokens: $model->outputTokens,
                cacheReadTokens: $model->cacheReadTokens,
                cacheWriteTokens: $model->cacheWriteTokens,
                costUsd: $model->costUsd,
            ));
        }

        return true;
    }
}
