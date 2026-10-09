<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\FactProvider;

final readonly class AgentReviewFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequests $trackedPullRequests,
        private CardPullRequestRepository $cardPullRequests,
        private AgentReviewRepository $agentReviews,
        private BoardAutomation $boardAutomation,
        private CardTypeCatalog $catalog,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return AgentReviewFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $snapshot): object
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('The card of the facts exists.');
        $enabled = $this->boardAutomation->settingsOf($card->project)->agentReview;
        $epic = $this->catalog->forProject($snapshot->projectId)->get($snapshot->type)->children;

        $open = array_values(array_filter(
            $this->trackedPullRequests->forCard($card),
            static fn (ForgePullRequest $pullRequest): bool => PullRequestState::Open === $pullRequest->state && null !== $pullRequest->headSha,
        ));
        $latest = $this->agentReviews->findLatestOfHeads($open);
        $heads = array_map(
            static fn (ForgePullRequest $pullRequest): ReviewedHead => new ReviewedHead((string) $pullRequest->id, $pullRequest->headSha ?? '', ($latest[(string) $pullRequest->id] ?? null)?->conclusion),
            $open,
        );

        return new AgentReviewFacts(
            $heads,
            $enabled,
            $epic,
            $enabled && [] !== $this->agentReviews->findUnpostedOfCard($card, $this->cardPullRequests->findOpenGitHubForCard($card)),
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof AgentReviewFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [
            $facts->enabled,
            $facts->epic,
            $facts->unposted,
            array_map(static fn (ReviewedHead $head): array => [$head->pullRequestId, $head->headSha, $head->conclusion?->value], $facts->heads),
        ];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.agent_review';
    }
}
