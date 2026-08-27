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
     * Returns the most recent review for a given version, or null if none exists.
     */
    public function findLatestByVersion(DocumentVersion $version): ?Review
    {
        $results = $this->findBy(['version' => $version], ['submittedAt' => 'DESC'], 1);

        return $results[0] ?? null;
    }

    /**
     * Every verdict on a version, newest first.
     *
     * @return list<Review>
     */
    public function findByVersionNewestFirst(DocumentVersion $version): array
    {
        return $this->findBy(['version' => $version], ['submittedAt' => 'DESC']);
    }

    /** @return list<Review> */
    public function findByReviewer(User $reviewer): array
    {
        return $this->findBy(['reviewer' => $reviewer], ['submittedAt' => 'DESC']);
    }
}
