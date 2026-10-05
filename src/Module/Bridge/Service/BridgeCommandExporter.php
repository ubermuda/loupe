<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\BridgeCommandRepository;

final readonly class BridgeCommandExporter implements UserDataExporterInterface
{
    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_commands.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->bridgeCommands->findByOwnerOrRequester($user) as $command) {
            yield [
                'commandId' => (string) $command->id,
                'project' => $command->project->name,
                'bridgeId' => (string) $command->bridgeId,
                'runId' => (string) $command->workerRun->id,
                'cardNumber' => $command->workerRun->cardNumber,
                'kind' => $command->kind->value,
                'state' => $command->state->value,
                'reason' => $command->reason,
                'cause' => $command->cause->value,
                'context' => $command->context->toArray(),
                // A boolean, so the file names no other person.
                'requestedByYou' => (string) $command->requestedBy?->id === (string) $user->id,
                'requestedAt' => $command->requestedAt->format(\DateTimeInterface::ATOM),
                'expiresAt' => $command->expiresAt->format(\DateTimeInterface::ATOM),
                'settledAt' => $command->settledAt?->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
