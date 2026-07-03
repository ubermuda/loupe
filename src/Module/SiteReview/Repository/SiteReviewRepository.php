<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteReview>
 */
class SiteReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReview::class);
    }

    public function findOneInProgress(Project $project): ?SiteReview
    {
        return $this->findOneBy(['project' => $project, 'status' => SiteReviewStatus::InProgress]);
    }

    /** @return list<SiteReview> */
    public function findForProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['createdAt' => 'DESC']);
    }

    /**
     * Count of submitted reviews for the project — the "n reviews" rollup on the
     * projects index. An in-progress (draft) review is not yet a review the human
     * has been handed, so only Submitted ones count.
     */
    public function countForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.project = :project')
            ->andWhere('r.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewStatus::Submitted)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
