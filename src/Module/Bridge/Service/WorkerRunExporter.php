<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;

final readonly class WorkerRunExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunStateChangeRepository $workerRunStateChanges,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_runs.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        // Keyed by the run object, which the identity map shares between both queries.
        /** @var \WeakMap<WorkerRun, list<array{state: string, at: string, receivedAt: string}>> $history */
        $history = new \WeakMap();
        foreach ($this->workerRunStateChanges->findByOwner($user) as $change) {
            $history[$change->run] = [...($history[$change->run] ?? []), [
                'state' => $change->state->value,
                'at' => $change->at->format(\DateTimeInterface::ATOM),
                'receivedAt' => $change->receivedAt->format(\DateTimeInterface::ATOM),
            ]];
        }

        foreach ($this->workerRuns->findByOwner($user) as $run) {
            yield [
                'project' => $run->project->name,
                'kind' => $run->kind->value,
                'bridgeId' => $run->bridgeId?->toRfc4122(),
                'runKey' => $run->runKey?->toRfc4122(),
                'state' => $run->state->value,
                'sessionId' => $run->sessionId?->toRfc4122(),
                'cardId' => (string) $run->cardId,
                'cardNumber' => $run->cardNumber,
                'ruleName' => $run->ruleName,
                'startedAt' => $run->startedAt?->format(\DateTimeInterface::ATOM),
                'endedAt' => $run->endedAt?->format(\DateTimeInterface::ATOM),
                'exitCode' => $run->exitCode,
                'hasResult' => $run->hasResult,
                'failureReason' => $run->failureReason,
                'output' => $run->output,
                'receivedAt' => $run->receivedAt->format(\DateTimeInterface::ATOM),
                'history' => $history[$run] ?? [],
            ];
        }
    }
}
