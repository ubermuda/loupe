<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
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

    /**
     * Fetch-joins comments so the site-review page — which renders every
     * comment on every review — costs one query instead of lazily
     * initializing a separate comments collection per review (N+1). The
     * comments' own #[ORM\OrderBy(['position' => 'ASC'])] mapping still
     * applies to the fetch-joined collection.
     *
     * @return list<SiteReview>
     */
    public function findForProject(Project $project): array
    {
        return $this->createQueryBuilder('sr')
            ->leftJoin('sr.comments', 'c')
            ->addSelect('c')
            ->andWhere('sr.project = :project')
            ->orderBy('sr.createdAt', 'DESC')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();
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

    /**
     * Site reviews hang off a project, not the owner directly, so this joins
     * through the project to filter by its owner.
     *
     * @return list<SiteReview>
     */
    public function findByOwner(User $user): array
    {
        return $this->createQueryBuilder('sr')
            ->join('sr.project', 'p')
            ->where('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('sr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
