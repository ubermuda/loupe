<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\SiteReview\Entity\Site;
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

    public function findOneInProgress(Site $site): ?SiteReview
    {
        return $this->findOneBy(['site' => $site, 'status' => SiteReviewStatus::InProgress]);
    }

    /** @return list<SiteReview> */
    public function findForSite(Site $site): array
    {
        return $this->findBy(['site' => $site], ['createdAt' => 'DESC']);
    }
}
