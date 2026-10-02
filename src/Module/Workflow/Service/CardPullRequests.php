<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;

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

    /** @return array{string, string} */
    private static function order(ForgePullRequest $pullRequest): array
    {
        return [$pullRequest->openedAt?->format('Y-m-d\TH:i:s.u') ?? '', (string) $pullRequest->id];
    }
}
