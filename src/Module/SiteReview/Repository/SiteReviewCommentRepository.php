<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
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
    public function findPendingForSite(Site $site): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.review', 'r')
            ->andWhere('r.site = :site')
            ->andWhere('r.status = :reviewStatus')
            ->andWhere('c.status = :commentStatus')
            ->setParameter('site', $site)
            ->setParameter('reviewStatus', SiteReviewStatus::Submitted)
            ->setParameter('commentStatus', SiteReviewCommentStatus::Pending)
            ->orderBy('r.submittedAt', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A comment inside the site's current in-progress review — the only
     * comments the widget may edit or delete.
     */
    public function findOneInDraftReview(Uuid $id, Site $site): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->join('c.review', 'r')
            ->andWhere('c.id = :id')
            ->andWhere('r.site = :site')
            ->andWhere('r.status = :status')
            ->setParameter('id', $id)
            ->setParameter('site', $site)
            ->setParameter('status', SiteReviewStatus::InProgress)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Owner-scoped lookup for the MCP addressing tool. */
    public function findOneForOwner(Uuid $id, User $owner): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->join('c.review', 'r')
            ->join('r.site', 's')
            ->andWhere('c.id = :id')
            ->andWhere('s.owner = :owner')
            ->setParameter('id', $id)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
