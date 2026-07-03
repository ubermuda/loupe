<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SiteReviewComment>
 */
class SiteReviewCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewComment::class);
    }

    /**
     * The agent queue: Pending comments on Submitted reviews, oldest review first.
     *
     * @return list<SiteReviewComment>
     */
    public function findPendingForProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.review', 'r')
            ->addSelect('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.status = :reviewStatus')
            ->andWhere('c.status = :commentStatus')
            ->setParameter('project', $project)
            ->setParameter('reviewStatus', SiteReviewStatus::Submitted)
            ->setParameter('commentStatus', SiteReviewCommentStatus::Pending)
            ->orderBy('r.submittedAt', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open-count for the app-shell nav pill: pending comments awaiting the agent
     * on submitted reviews. Mirrors {@see findPendingForProject} (Submitted
     * review + Pending comment) so the pill and the agent queue never disagree.
     */
    public function countOpenForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.review', 'r')
            ->andWhere('r.project = :project')
            ->andWhere('r.status = :reviewStatus')
            ->andWhere('c.status = :commentStatus')
            ->setParameter('project', $project)
            ->setParameter('reviewStatus', SiteReviewStatus::Submitted)
            ->setParameter('commentStatus', SiteReviewCommentStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A comment inside the project's current in-progress review — the only
     * comments the widget may edit or delete.
     */
    public function findOneInDraftReview(Uuid $id, Project $project): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->join('c.review', 'r')
            ->andWhere('c.id = :id')
            ->andWhere('r.project = :project')
            ->andWhere('r.status = :status')
            ->setParameter('id', $id)
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewStatus::InProgress)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Project-scoped lookup for the MCP addressing tool. */
    public function findOneForProject(Uuid $id, Project $project): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->join('c.review', 'r')
            ->addSelect('r')
            ->andWhere('c.id = :id')
            ->andWhere('r.project = :project')
            ->setParameter('id', $id)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
