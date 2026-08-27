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
     * Streams rather than returns a list: the only caller is the full account
     * export, which has no page to bound it and no reason to hold every review
     * of a long-lived account at once.
     *
     * @return iterable<Review>
     */
    public function streamByReviewer(User $reviewer): iterable
    {
        return $this->createQueryBuilder('review')
            ->andWhere('review.reviewer = :reviewer')
            ->setParameter('reviewer', $reviewer)
            ->orderBy('review.submittedAt', 'DESC')
            ->getQuery()
            ->toIterable();
    }
}
