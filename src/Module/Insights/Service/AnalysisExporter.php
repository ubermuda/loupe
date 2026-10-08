<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\ProposalRepository;

/** The analyses of the projects the user owns, each with its proposals. */
final readonly class AnalysisExporter implements UserDataExporterInterface
{
    public function __construct(
        private AnalysisRepository $analyses,
        private ProposalRepository $proposals,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'insights_analyses.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->analyses->findByOwner($user) as $analysis) {
            yield [
                'analysisId' => (string) $analysis->id,
                'project' => $analysis->project->name,
                'topic' => $analysis->topic->value,
                'scope' => $analysis->scope->toArray(),
                'question' => $analysis->question,
                'model' => $analysis->model,
                'effort' => $analysis->effort,
                'workRequestId' => $analysis->workRequestId?->toRfc4122(),
                'documentId' => $analysis->documentId?->toRfc4122(),
                'state' => $analysis->state->value,
                'reason' => $analysis->reason,
                'costMicroUsd' => $this->analyses->costOf($analysis),
                'createdAt' => $analysis->createdAt->format(\DateTimeInterface::ATOM),
                'finishedAt' => $analysis->finishedAt?->format(\DateTimeInterface::ATOM),
                'proposals' => array_map(static fn (Proposal $proposal): array => [
                    'proposalId' => (string) $proposal->id,
                    'kind' => $proposal->kind->value,
                    'title' => $proposal->title,
                    'body' => $proposal->body,
                    'payload' => $proposal->payload,
                    'estimatedSaving' => $proposal->estimatedSaving,
                    'state' => $proposal->state->value,
                    'dismissReason' => $proposal->dismissReason,
                    'cardId' => $proposal->cardId?->toRfc4122(),
                    'position' => $proposal->position,
                ], $this->proposals->findByAnalysis($analysis)),
            ];
        }
    }
}
