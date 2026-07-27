<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

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
}
