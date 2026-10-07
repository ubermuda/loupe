<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunFactRepository;

/** A file of its own, because a fact row outlives the run that WorkerRunExporter writes. */
final readonly class WorkerRunFactExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunFactRepository $workerRunFacts,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_facts.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunFacts->findByOwner($user) as $fact) {
            yield [
                'runId' => (string) $fact->runId,
                'project' => $fact->project->name,
                'subjectType' => $fact->subjectType,
                'subjectId' => (string) $fact->subjectId,
                'cardNumber' => $fact->cardNumber,
                'kind' => $fact->kind->value,
                'workKind' => $fact->workKind,
                'ruleId' => $fact->ruleId,
                'experiment' => $fact->experiment,
                'variant' => $fact->variant,
                'model' => $fact->model,
                'bridgeId' => $fact->bridgeId?->toRfc4122(),
                'outcome' => $fact->outcome->value,
                'startedAt' => $fact->startedAt?->format(\DateTimeInterface::ATOM),
                'endedAt' => $fact->endedAt?->format(\DateTimeInterface::ATOM),
                'receivedAt' => $fact->receivedAt->format(\DateTimeInterface::ATOM),
                'durationMs' => $fact->durationMs,
                'costMicroUsd' => $fact->costMicroUsd,
                'tokensIn' => $fact->tokensIn,
                'tokensOut' => $fact->tokensOut,
                'tokensCacheRead' => $fact->tokensCacheRead,
                'tokensCacheWrite' => $fact->tokensCacheWrite,
                'usageSource' => $fact->usageSource?->value,
                'toolTimeMs' => $fact->toolTimeMs,
                'modelTimeMs' => $fact->modelTimeMs,
                'toolCalls' => $fact->toolCalls,
                'failedCalls' => $fact->failedCalls,
                'longestCallMs' => $fact->longestCallMs,
                'idleGapMs' => $fact->idleGapMs,
                'subagentMs' => $fact->subagentMs,
                'peakContextTokens' => $fact->peakContextTokens,
            ];
        }
    }
}
