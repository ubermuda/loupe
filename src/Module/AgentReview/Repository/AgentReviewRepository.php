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
