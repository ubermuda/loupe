<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;

final readonly class WorkerRunToolCallExporter implements UserDataExporterInterface
{
    public function __construct(
        private WorkerRunToolCallRepository $workerRunToolCalls,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'worker_run_tool_calls.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->workerRunToolCalls->findByOwner($user) as $call) {
            yield [
                'project' => $call->run->project->name,
                'runKey' => $call->run->runKey?->toRfc4122(),
                'seq' => $call->seq,
                'tool' => $call->tool,
                'kind' => $call->kind?->value,
                'startedAt' => $call->startedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                'durationMs' => $call->durationMs,
                'isError' => $call->isError,
                'inSubagent' => $call->inSubagent,
                'backgroundId' => $call->backgroundId,
                'waitsOn' => $call->waitsOn,
                'signatures' => $call->signatures,
                'fullText' => $call->fullText,
            ];
        }
    }
}
