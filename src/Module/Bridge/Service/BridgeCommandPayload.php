<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\BridgeCommand;

/**
 * The shape of one command as the bridge reads it, in the outbox event and in
 * the heartbeat reply alike.
 */
final class BridgeCommandPayload
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function of(BridgeCommand $command): array
    {
        $run = $command->workerRun;

        // Every key is a contract with the bridge. Ids and names only: text a
        // person wrote must never reach an agent through a command.
        return [
            'type' => BridgeEventType::COMMAND,
            'projectId' => (string) $command->project->id,
            'subject' => ['type' => 'bridge-command', 'id' => (string) $command->id],
            'commandId' => (string) $command->id,
            'kind' => $command->kind->value,
            'bridgeId' => (string) $command->bridgeId,
            'runKey' => null === $run->runKey ? null : (string) $run->runKey,
            'sessionId' => null === $run->sessionId ? null : (string) $run->sessionId,
            'cardId' => (string) $run->cardId,
            'cardNumber' => $run->cardNumber,
            'workRequestId' => $run->workRequestId?->toRfc4122(),
            'workKind' => $run->workKind,
            'ruleId' => $run->ruleId,
            'expiresAt' => $command->expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
