<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SiteReviewBatch>
 */
class SiteReviewBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewBatch::class);
    }

    public function findOneByIdAndOwner(Uuid $id, User $owner): ?SiteReviewBatch
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }
}
