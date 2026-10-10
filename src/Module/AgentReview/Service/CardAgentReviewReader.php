<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @phpstan-import-type AgentReviewSummary from CardAgentReviews
 */
#[AsAlias(CardAgentReviews::class)]
final readonly class CardAgentReviewReader implements CardAgentReviews
{
    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private AgentReviewRepository $agentReviews,
    ) {
    }

    #[\Override]
    public function forCards(array $cards): array
    {
        $cardsByProject = [];
        foreach ($cards as $card) {
            $cardsByProject[(string) $card->project->id][] = $card;
        }

        $summaries = [];
        foreach ($cardsByProject as $projectCards) {
            $summaries += $this->forProject($projectCards);
        }

        return $summaries;
    }

    /**
     * @param non-empty-list<Card> $cards all of one project
     *
     * @return array<string, AgentReviewSummary>
     */
    private function forProject(array $cards): array
    {
        $keys = [];
        foreach ($cards as $card) {
            foreach ($card->pullRequests as $link) {
                if (null !== $link->repository && null !== $link->number) {
                    $keys[] = ['forge' => $link->forge->value, 'repository' => $link->repository, 'number' => $link->number];
                }
            }
        }

        $projectId = $cards[0]->project->id ?? throw new \LogicException('A card project is persisted.');
        $forgeRows = $this->forgePullRequests->findByKeys($projectId, $keys);
        $rowIds = [];
        foreach ($forgeRows as $row) {
            $rowIds[self::key($row->forge, $row->repository, $row->number)] = (string) $row->id;
        }

        $latest = [];
        foreach ($this->agentReviews->findLatestOfCards($cards, $forgeRows) as $review) {
            $latest[$review->card->id.' '.$review->pullRequest->id] = $review;
        }

        $summaries = [];
        foreach ($cards as $card) {
            foreach ($card->pullRequests as $link) {
                if (null === $link->repository || null === $link->number) {
                    continue;
                }
                $rowId = $rowIds[self::key($link->forge->value, $link->repository, $link->number)] ?? null;
                $review = null === $rowId ? null : ($latest[$card->id.' '.$rowId] ?? null);
                if (null === $review) {
                    continue;
                }
                $summaries[(string) $link->id] = [
                    'reviewId' => (string) $review->id,
                    'headSha' => $review->headSha,
                    'conclusion' => $review->conclusion->value,
                    'summary' => $review->summary,
                    'findings' => array_map(static fn ($finding): array => $finding->toArray(), $review->findings()),
                    'createdAt' => $review->createdAt->format(\DATE_ATOM),
                    'postedAt' => $review->postedAt?->format(\DATE_ATOM),
                ];
            }
        }

        return $summaries;
    }

    private static function key(string $forge, string $repository, int $number): string
    {
        return $forge.' '.mb_strtolower($repository).'#'.$number;
    }
}
