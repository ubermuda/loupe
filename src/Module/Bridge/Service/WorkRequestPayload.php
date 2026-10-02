<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\WorkRequest;

/**
 * The shape of one work request as a bridge reads it, in the outbox event and
 * in the heartbeat reply alike.
 */
final class WorkRequestPayload
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function of(WorkRequest $request): array
    {
        // Every key is a contract with the bridge. Ids and names only, and
        // never the claim token, because every subscriber of the project reads this.
        return [
            'type' => BridgeEventType::WORK_REQUEST,
            'projectId' => (string) $request->project->id,
            'subject' => ['type' => 'work-request', 'id' => (string) $request->id],
            'workRequestId' => (string) $request->id,
            'kind' => $request->kind,
            'capability' => $request->capability,
            'state' => $request->state->value,
            'cardId' => (string) $request->cardId,
            'cardNumber' => $request->cardNumber,
            'ruleId' => $request->ruleId,
            'createdAt' => $request->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
