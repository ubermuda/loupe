<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
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
     * The last row written to a version's verdict log, or null if none has been.
     *
     * A withdrawal is a row like any other, so this may be one — read
     * findStandingVerdictByVersion() for "what the verdict currently is".
     *
     * Ordered by sequence, never by submittedAt: that column is TIMESTAMP(0), so
     * rows written in the same second are indistinguishable by it.
     */
    public function findNewestByVersion(DocumentVersion $version): ?Review
    {
        return $this->findBy(['version' => $version], ['sequence' => 'DESC'], 1)[0] ?? null;
    }

    /**
     * The verdict standing on a version, or null when there is none — either because
     * no verdict was ever given, or because the last one was withdrawn.
     */
    public function findStandingVerdictByVersion(DocumentVersion $version): ?Review
    {
        $newest = $this->findNewestByVersion($version);

        return null !== $newest && Verdict::Withdrawn !== $newest->verdict ? $newest : null;
    }

    /**
     * The position the next row on this version takes.
     *
     * Only safe to call under the document's write lock, which both writers hold —
     * otherwise two appends read the same maximum and collide on the UNIQUE index.
     */
    public function nextSequenceFor(DocumentVersion $version): int
    {
        $newest = $this->findNewestByVersion($version);

        return null === $newest ? 1 : $newest->sequence + 1;
    }

    /**
     * When this reviewer last submitted a verdict on any version of a document,
     * or null if they never have.
     *
     * A withdrawal counts like any other row: it is still the reviewer engaging
     * with that version.
     */
    public function findLatestSubmittedAtByDocumentAndReviewer(Document $document, User $reviewer): ?\DateTimeImmutable
    {
        $row = $this->createQueryBuilder('review')
            ->select('review.submittedAt AS submittedAt')
            ->join('review.version', 'v')
            ->andWhere('v.document = :document')
            ->andWhere('review.reviewer = :reviewer')
            ->setParameter('document', $document)
            ->setParameter('reviewer', $reviewer)
            ->orderBy('review.submittedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row)) {
            return null;
        }

        $submittedAt = $row['submittedAt'];

        return $submittedAt instanceof \DateTimeImmutable
            ? $submittedAt
            : throw new \LogicException('submittedAt must be a DateTimeImmutable.');
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
