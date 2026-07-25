<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return list<Comment>
     */
    public function findOpenByVersion(DocumentVersion $version): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.version = :version')
            ->andWhere('c.resolved = :resolved')
            ->setParameter('version', $version)
            ->setParameter('resolved', false)
            // Document order: offsetHint is the quote's position in plainText(),
            // so threads list top-to-bottom as they appear in the document.
            ->orderBy('c.anchor.offsetHint', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts the open threads on a version: top-level (parent IS NULL) comments
     * that are not resolved. Replies never count as threads of their own.
     */
    public function countOpenByVersion(DocumentVersion $version): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.version = :version')
            ->andWhere('c.resolved = :resolved')
            ->andWhere('c.parent IS NULL')
            ->setParameter('version', $version)
            ->setParameter('resolved', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns all comments for a version (both resolved and open), in document
     * order (by anchor offset, then id for stable ties).
     *
     * @return list<Comment>
     */
    public function findByVersion(DocumentVersion $version): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.version = :version')
            ->setParameter('version', $version)
            // Document order — see findOpenByVersion().
            ->orderBy('c.anchor.offsetHint', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Direct replies to a comment, in creation order (matching the sibling
     * order used when rendering the full version on the review page).
     *
     * @return list<Comment>
     */
    public function findReplies(Comment $parent): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findByAuthor(User $author): array
    {
        return $this->findBy(['author' => $author]);
    }
}
