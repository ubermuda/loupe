<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * The verdict on a given version, or null if none has been submitted.
     *
     * There is at most one — the relation is OneToOne — so this needs no ordering
     * and cannot pick the wrong row.
     */
    public function findByVersion(DocumentVersion $version): ?Review
    {
        return $this->findOneBy(['version' => $version]);
    }

    /** @return list<Review> */
    public function findByReviewer(User $reviewer): array
    {
        return $this->findBy(['reviewer' => $reviewer], ['submittedAt' => 'DESC']);
    }
}
