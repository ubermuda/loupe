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
        // Every key is a contract with the bridge, and every subscriber of the project reads this.
        // Ids, names and context values of a strict shape only, and never the claim token.
        return [
            'type' => BridgeEventType::WORK_REQUEST,
            'projectId' => (string) $request->project->id,
            'subject' => ['type' => 'work-request', 'id' => (string) $request->id],
            'workRequestId' => (string) $request->id,
            'subjectType' => $request->subjectType,
            'subjectId' => $request->subjectId->toRfc4122(),
            'kind' => $request->kind,
            'capability' => $request->capability,
            'state' => $request->state->value,
            // A label a person reads, for a card subject alone. The bridge never reads a card from it.
            'cardNumber' => $request->cardNumber,
            'ruleId' => $request->ruleId,
            'createdAt' => $request->createdAt->format(\DateTimeInterface::ATOM),
            'resumeSessionId' => $request->resumeSessionId?->toRfc4122(),
            'context' => $request->context->toArray(),
            'model' => $request->model,
            'effort' => $request->effort,
        ];
    }
}
