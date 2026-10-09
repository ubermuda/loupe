<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Workflow\Contract\PullRequestFacts;

/** The pull requests that Forge tracks for the links of a card, and the one the engine acts on. */
final readonly class CardPullRequests
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
    ) {
    }

    /**
     * A link that names no pull request, or that Forge does not track, gives nothing.
     *
     * @return list<ForgePullRequest> by forge, repository and number
     */
    public function forCard(Card $card): array
    {
        $keys = [];
        foreach ($this->cardPullRequests->findCurrentKeys($card) as $link) {
            if (null !== $link['repository'] && null !== $link['number']) {
                $keys[] = ['forge' => $link['forge'], 'repository' => $link['repository'], 'number' => $link['number']];
            }
        }

        $projectId = $card->project->id ?? throw new \LogicException('A stored card has a project id.');
        $pullRequests = $this->forgePullRequests->findByKeys($projectId, $keys);
        usort($pullRequests, static fn (ForgePullRequest $a, ForgePullRequest $b): int => [$a->forge, $a->repository, $a->number] <=> [$b->forge, $b->repository, $b->number]);

        return $pullRequests;
    }

    public function childMergedInto(Card $parent, string $baseBranch): bool
    {
        return $this->cardPullRequests->hasChildMergedInto($parent, $baseBranch);
    }

    /** Whether the card links the pull request, whatever case its link spells the repository in. */
    public function links(Card $card, string $forge, string $repository, int $number): bool
    {
        return null !== $this->cardPullRequests->findUrlOfPullRequest($card, $forge, $repository, $number);
    }

    /** @return list<string> every link URL of the card, read past the identity map */
    public function currentUrls(Card $card): array
    {
        return $this->cardPullRequests->findCurrentUrls($card);
    }

    /** The pull request of a child of the card that merged into the branch last, as Forge tracks it. */
    public function lastChildMergedInto(Card $parent, string $baseBranch): ?ForgePullRequest
    {
        $key = $this->cardPullRequests->findLastChildMergedInto($parent, $baseBranch);
        if (null === $key) {
            return null;
        }
        $projectId = $parent->project->id ?? throw new \LogicException('A stored card has a project id.');

        return $this->forgePullRequests->findByKeys($projectId, [$key])[0] ?? null;
    }

    /**
     * The open pull request opened last, else the pull request opened last. A pull request
     * with no opening time sorts first, and the id breaks a tie.
     *
     * @param list<ForgePullRequest> $pullRequests
     */
    public function primary(array $pullRequests): ?ForgePullRequest
    {
        $open = array_filter($pullRequests, static fn (ForgePullRequest $pullRequest): bool => PullRequestState::Open === $pullRequest->state);
        $candidates = [] === $open ? $pullRequests : $open;

        $primary = null;
        foreach ($candidates as $pullRequest) {
            if (null === $primary || self::order($pullRequest) > self::order($primary)) {
                $primary = $pullRequest;
            }
        }

        return $primary;
    }

    /**
     * The pull request the facts read, by its id. Facts with no id fall back to the primary one.
     *
     * @param list<ForgePullRequest> $pullRequests
     */
    public function subjectOf(array $pullRequests, ?PullRequestFacts $facts): ?ForgePullRequest
    {
        if (null === $facts?->id) {
            return $this->primary($pullRequests);
        }

        return array_find($pullRequests, static fn (ForgePullRequest $pullRequest): bool => true === $pullRequest->id?->equals($facts->id));
    }

    /** @return array{string, string} */
    private static function order(ForgePullRequest $pullRequest): array
    {
        return [$pullRequest->openedAt?->format('Y-m-d\TH:i:s.u') ?? '', (string) $pullRequest->id];
    }
}
