<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunRepository;

final readonly class WorkerRunExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
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
        foreach ($this->workerRuns->findByOwner($user) as $run) {
            yield [
                'project' => $run->project->name,
                'bridgeId' => (string) $run->bridgeId,
                'sessionId' => (string) $run->sessionId,
                'cardId' => (string) $run->cardId,
                'cardNumber' => $run->cardNumber,
                'ruleName' => $run->ruleName,
                'startedAt' => $run->startedAt->format(\DateTimeInterface::ATOM),
                'endedAt' => $run->endedAt->format(\DateTimeInterface::ATOM),
                'exitCode' => $run->exitCode,
                'failureReason' => $run->failureReason,
                'output' => $run->output,
                'receivedAt' => $run->receivedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
