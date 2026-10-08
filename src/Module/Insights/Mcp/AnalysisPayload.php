<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Command\AnalysisDetailView;
use App\Module\Insights\Entity\Proposal;

/**
 * The shape analysis_get and analysis_report answer with.
 *
 * @phpstan-type ProposalPayload array{id: string, kind: string, title: string, body: string, payload: array<mixed>|null, estimatedSaving: ?string, state: string, dismissReason: ?string, cardId: ?string}
 * @phpstan-type AnalysisPayloadShape array{id: string, topic: string, scope: array{range: string, experiment?: string}, question: ?string, model: string, effort: string, state: string, reason: ?string, createdAt: string, finishedAt: ?string, documentId: ?string, costUsd: ?float, proposals: list<ProposalPayload>}
 */
final readonly class AnalysisPayload
{
    /** @return AnalysisPayloadShape */
    public static function of(AnalysisDetailView $view): array
    {
        $analysis = $view->analysis;

        return [
            'id' => (string) $analysis->id,
            'topic' => $analysis->topic->value,
            'scope' => $analysis->scope->toArray(),
            'question' => $analysis->question,
            'model' => $analysis->model,
            'effort' => $analysis->effort,
            'state' => $analysis->state->value,
            'reason' => $analysis->reason,
            'createdAt' => $analysis->createdAt->format(\DATE_ATOM),
            'finishedAt' => $analysis->finishedAt?->format(\DATE_ATOM),
            'documentId' => $analysis->documentId?->toRfc4122(),
            'costUsd' => null === $view->costMicroUsd ? null : $view->costMicroUsd / 1_000_000.0,
            'proposals' => array_map(static fn (Proposal $proposal): array => [
                'id' => (string) $proposal->id,
                'kind' => $proposal->kind->value,
                'title' => $proposal->title,
                'body' => $proposal->body,
                'payload' => $proposal->payload,
                'estimatedSaving' => $proposal->estimatedSaving,
                'state' => $proposal->state->value,
                'dismissReason' => $proposal->dismissReason,
                'cardId' => $proposal->cardId?->toRfc4122(),
            ], $view->proposals),
        ];
    }
}
