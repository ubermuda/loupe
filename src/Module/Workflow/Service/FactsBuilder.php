<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState as ForgePullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Workflow\Fact\CardFacts;
use App\Module\Workflow\Fact\ChecksState;
use App\Module\Workflow\Fact\DocumentFacts;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestFacts;
use App\Module\Workflow\Fact\PullRequestState;
use App\Module\Workflow\Fact\RunFacts;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;

/** Reads what the engine knows about one card, from Board, Forge and Bridge. */
final readonly class FactsBuilder
{
    public const string BACKLOG_SLOT = '@backlog';

    public const string TERMINAL_SLOT = '@terminal';

    public function __construct(
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
        private CardPullRequests $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkRequestRepository $workRequests,
    ) {
    }

    public function build(Card $card, \DateTimeImmutable $now): Facts
    {
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $children = $this->cards->childProgressOf($card);

        $epicBranches = [];
        if (null !== $card->parent) {
            foreach ($this->cardPullRequests->forCard($card->parent) as $epicPullRequest) {
                if (ForgePullRequestState::Open === $epicPullRequest->state && null !== $epicPullRequest->headBranch) {
                    $epicBranches[] = self::branchKey($epicPullRequest, $epicPullRequest->headBranch);
                }
            }
        }

        $pullRequests = $this->cardPullRequests->forCard($card);
        $pullRequestFacts = array_map(fn (ForgePullRequest $pullRequest): PullRequestFacts => $this->pullRequestFacts($pullRequest, $epicBranches), $pullRequests);
        $primaryIndex = array_search($this->cardPullRequests->primary($pullRequests), $pullRequests, true);
        $settled = $this->workRequests->findLatestSettledForCard($cardId);

        return new Facts(
            now: $now,
            card: new CardFacts(
                slot: $this->slot($card),
                type: $card->type->value,
                hasOpenBlocker: [] !== $this->cards->findOpenBlockersOf($card),
                isChild: null !== $card->parent,
                childCount: $children['total'],
                openChildCount: $children['total'] - $children['done'],
                documents: array_map(
                    static fn (array $document): DocumentFacts => new DocumentFacts($document['tags'], $document['status']),
                    $this->cardDocuments->findStatusesAndTagsForCard($card),
                ),
            ),
            pullRequest: false === $primaryIndex ? null : $pullRequestFacts[$primaryIndex],
            pullRequests: $pullRequestFacts,
            run: new RunFacts(
                activeWorkKinds: array_values(array_unique(array_map(
                    static fn ($request): string => $request->kind,
                    $this->workRequests->findLiveForCard($cardId),
                ))),
                lastRefusalCode: WorkRequestState::Refused === $settled?->state ? $settled->reason : null,
            ),
        );
    }

    private function slot(Card $card): ?string
    {
        return match (true) {
            $card->column->backlog => self::BACKLOG_SLOT,
            $card->column->terminal => self::TERMINAL_SLOT,
            default => $this->workflowSlotLinks->findSlotKeyForColumn($card->project, $card->column),
        };
    }

    /** @param list<string> $epicBranches the branch keys of the open pull requests of the card's epic */
    private function pullRequestFacts(ForgePullRequest $pullRequest, array $epicBranches): PullRequestFacts
    {
        $base = $pullRequest->baseBranch;
        $baseIsEpicBranch = null !== $base && \in_array(self::branchKey($pullRequest, $base), $epicBranches, true);

        $parents = [];
        if (null !== $base && $base !== $pullRequest->defaultBranch) {
            $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
            $parents = array_filter(
                $this->forgePullRequests->findByHeadBranch($projectId, $pullRequest->forge, $pullRequest->repository, $base),
                static fn (ForgePullRequest $parent): bool => $parent->id?->toRfc4122() !== $pullRequest->id?->toRfc4122(),
            );
        }

        return new PullRequestFacts(
            state: PullRequestState::from($pullRequest->state->value),
            draft: $pullRequest->draft,
            checks: match ($pullRequest->checks) {
                PullRequestChecks::Pending => ChecksState::Pending,
                PullRequestChecks::Passed => ChecksState::Passed,
                PullRequestChecks::Failed => ChecksState::Failed,
            },
            conflicting: PullRequestMergeability::Conflicting === $pullRequest->mergeability,
            behind: PullRequestMergeability::Behind === $pullRequest->mergeability,
            approvalsCoveringHead: PullRequestReview::Approved === $pullRequest->review
                && null !== $pullRequest->coveredSha
                && $pullRequest->coveredSha === $pullRequest->headSha ? 1 : 0,
            changesRequested: PullRequestReview::ChangesRequested === $pullRequest->review,
            baseIsMergeTarget: $baseIsEpicBranch || (null !== $base && $base === $pullRequest->defaultBranch),
            baseIsEpicBranch: $baseIsEpicBranch,
            stacked: [] !== $parents,
            parentMerged: array_any($parents, static fn (ForgePullRequest $parent): bool => ForgePullRequestState::Merged === $parent->state),
            closedAt: match ($pullRequest->state) {
                ForgePullRequestState::Open => null,
                ForgePullRequestState::Merged => $pullRequest->mergedAt,
                // Forge records no close time, so the last read of the pull request stands in for it.
                ForgePullRequestState::Closed => $pullRequest->refreshedAt,
            },
        );
    }

    private static function branchKey(ForgePullRequest $pullRequest, string $branch): string
    {
        return $pullRequest->forge.' '.$pullRequest->repository.' '.$branch;
    }
}
