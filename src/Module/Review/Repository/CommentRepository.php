<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
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
     * Comments belonging to a thread that is still open — status lives on the
     * thread root, so a reply qualifies on its parent's status, not its own.
     *
     * Addressed threads are open: the agent claiming it acted is not the human
     * agreeing the thread is finished, so they keep carrying forward.
     *
     * @return list<Comment>
     */
    public function findOpenByVersion(DocumentVersion $version): array
    {
        return $this->createQueryBuilder('c')
            // LEFT, not INNER: a root comment has no parent row, and an inner
            // join would drop every root from the result.
            ->leftJoin('c.parent', 'p')
            ->where('c.version = :version')
            ->andWhere('COALESCE(p.status, c.status) != :resolved')
            ->setParameter('version', $version)
            ->setParameter('resolved', CommentStatus::Resolved)
            // Document order: offsetHint is the quote's position in plainText(),
            // so threads list top-to-bottom as they appear in the document.
            ->orderBy('c.anchor.offsetHint', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts the open threads on a version: top-level (parent IS NULL) comments
     * that are not resolved. Replies never count as threads of their own — and
     * with the filter restricted to roots, their status never has to be joined.
     */
    public function countOpenByVersion(DocumentVersion $version): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.version = :version')
            ->andWhere('c.status != :resolved')
            ->andWhere('c.parent IS NULL')
            ->setParameter('version', $version)
            ->setParameter('resolved', CommentStatus::Resolved)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns all comments for a version (open threads and resolved ones), in
     * document order (by anchor offset, then id for stable ties).
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
