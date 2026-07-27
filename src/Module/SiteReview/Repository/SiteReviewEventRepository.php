<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteReviewEvent>
 */
class SiteReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewEvent::class);
    }

    /**
     * One row per submit, so this is the "n reviews" rollup on the projects
     * index — a review is a Send, and a Send always writes exactly one event.
     */
    public function countForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
