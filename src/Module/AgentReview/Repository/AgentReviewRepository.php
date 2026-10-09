<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Repository;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AgentReview> */
class AgentReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentReview::class);
    }

    /**
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return list<AgentReview> the reviews of the card on those pull requests that no check shows yet, oldest first
     */
    public function findUnpostedOfCard(Card $card, array $pullRequests): array
    {
        if ([] === $pullRequests) {
            return [];
        }

        /** @var list<AgentReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->where('r.card = :card AND r.pullRequest IN (:pullRequests) AND r.postedAt IS NULL')
            ->setParameter('card', $card)
            ->setParameter('pullRequests', $pullRequests)
            ->orderBy('r.createdAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        return $reviews;
    }

    /**
     * The newest review of the current head of each pull request, whichever card asked for it.
     *
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return array<string, AgentReview> keyed by pull request id; a pull request with no head or no review of its head is absent
     */
    public function findLatestOfHeads(array $pullRequests): array
    {
        $heads = [];
        foreach ($pullRequests as $pullRequest) {
            if (null !== $pullRequest->headSha) {
                $heads[(string) $pullRequest->id] = mb_strtolower($pullRequest->headSha);
            }
        }
        if ([] === $heads) {
            return [];
        }

        /** @var list<AgentReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->where('r.pullRequest IN (:pullRequests) AND LOWER(r.headSha) IN (:heads)')
            ->setParameter('pullRequests', array_filter($pullRequests, static fn (ForgePullRequest $pullRequest): bool => null !== $pullRequest->headSha))
            ->setParameter('heads', array_values(array_unique($heads)))
            ->orderBy('r.createdAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($reviews as $review) {
            $key = (string) $review->pullRequest->id;
            if (($heads[$key] ?? null) === mb_strtolower($review->headSha)) {
                $latest[$key] = $review;
            }
        }

        return $latest;
    }

    /**
     * @param list<Card>             $cards
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return list<AgentReview> for each card and pull request pair, the newest review (by creation time, then id)
     */
    public function findLatestOfCards(array $cards, array $pullRequests): array
    {
        if ([] === $cards || [] === $pullRequests) {
            return [];
        }

        /** @var list<AgentReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->where('r.card IN (:cards) AND r.pullRequest IN (:pullRequests)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.AgentReview::class.' n WHERE n.card = r.card AND n.pullRequest = r.pullRequest AND (n.createdAt > r.createdAt OR (n.createdAt = r.createdAt AND n.id > r.id)))')
            ->setParameter('cards', $cards)
            ->setParameter('pullRequests', $pullRequests)
            ->getQuery()
            ->getResult();

        return $reviews;
    }

    /** @return int the number of reviews deleted */
    public function deleteByProject(Project $project): int
    {
        $deleted = $this->getEntityManager()
            ->createQuery('DELETE App\Module\AgentReview\Entity\AgentReview r WHERE r.project = :project')
            ->setParameter('project', $project)
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
