<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\ValueObject\Anchor;
use App\Module\Review\ValueObject\Engagement;
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
     * Same count as countOpenByVersion, for several versions in one query. The
     * documents list renders a page of rows, so the per-version form is an N+1
     * across the whole page.
     *
     * @param list<string> $versionIds
     *
     * @return array<string, int> keyed by version id; a version with no open
     *                            threads is absent rather than zero
     */
    public function countOpenByVersions(array $versionIds): array
    {
        if ([] === $versionIds) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.version) AS id, COUNT(c.id) AS total')
            ->where('c.version IN (:versions)')
            ->andWhere('c.status != :resolved')
            ->andWhere('c.parent IS NULL')
            ->setParameter('versions', $versionIds)
            ->setParameter('resolved', CommentStatus::Resolved)
            ->groupBy('c.version')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
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
            // CommentThread renders author.fullName for every row, so without
            // this each comment costs its own query to load the author proxy.
            ->addSelect('author')
            ->join('c.author', 'author')
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
            // Replies render their author too — same reason as findByVersion().
            ->addSelect('author')
            ->join('c.author', 'author')
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

    /**
     * This author's most recent comment on a document, and the version they were
     * looking at when they wrote it. Null if they never commented on it.
     *
     * The version is the LOWEST of the rows sharing that createdAt, not the
     * highest: a revision copies every open comment onto the new version and the
     * copy inherits the original's createdAt, so one comment written on v3 exists
     * as rows on v3, v4 and v5. The earliest of them is where it was written.
     */
    public function findLatestEngagementByDocumentAndAuthor(Document $document, User $author): ?Engagement
    {
        $row = $this->createQueryBuilder('c')
            ->select('c.createdAt AS createdAt', 'v.versionNumber AS versionNumber')
            ->join('c.version', 'v')
            ->andWhere('v.document = :document')
            ->andWhere('c.author = :author')
            // Load-bearing: createdAt is nullable for comments written before the
            // column existed, and Postgres orders NULLS FIRST on a DESC sort.
            ->andWhere('c.createdAt IS NOT NULL')
            ->setParameter('document', $document)
            ->setParameter('author', $author)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('v.versionNumber', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row)) {
            return null;
        }

        $createdAt = $row['createdAt'];
        $versionNumber = $row['versionNumber'];

        return new Engagement(
            $createdAt instanceof \DateTimeImmutable ? $createdAt : throw new \LogicException('createdAt must be a DateTimeImmutable.'),
            is_int($versionNumber) ? $versionNumber : throw new \LogicException('versionNumber must be an int.'),
        );
    }

    /**
     * Pending → Addressed as a single conditional statement, so a human who
     * clicks Resolve between the caller's read and this write keeps their
     * resolution instead of having it silently replaced. Returns false when the
     * row was no longer pending.
     */
    public function markAddressedIfPending(Comment $comment): bool
    {
        $updated = $this->createQueryBuilder('c')
            ->update()
            ->set('c.status', ':addressed')
            ->andWhere('c.id = :id')
            ->andWhere('c.status = :pending')
            ->setParameter('addressed', CommentStatus::Addressed)
            ->setParameter('id', $comment->id, 'uuid')
            ->setParameter('pending', CommentStatus::Pending)
            ->getQuery()
            ->execute();

        if (0 === $updated) {
            return false;
        }

        // A DQL update bypasses the identity map. The snapshot must move with
        // the copy, or the next flush reissues this as an unconditional UPDATE
        // and the race reopens. refresh() cannot do it: Doctrine refuses to
        // rehydrate the entity's readonly property.
        $comment->status = CommentStatus::Addressed;
        $this->getEntityManager()->getUnitOfWork()->setOriginalEntityProperty(
            spl_object_id($comment),
            'status',
            CommentStatus::Addressed,
        );

        return true;
    }

    /**
     * The status the row carries right now, read past the identity map. Only
     * useful after {@see markAddressedIfPending} returns false, to tell a
     * concurrent Resolve from a concurrent Addressed. Null if the row is gone.
     */
    public function currentStatus(Comment $comment): ?CommentStatus
    {
        $row = $this->createQueryBuilder('c')
            ->select('c.status')
            ->andWhere('c.id = :id')
            ->setParameter('id', $comment->id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($row) && $row['status'] instanceof CommentStatus ? $row['status'] : null;
    }

    /**
     * Moves a comment's anchor onto re-rendered text, in place.
     *
     * The whole anchor, not just the offset. The browser never receives
     * offsetHint — it re-locates each comment by quote and by the surrounding
     * prefix and suffix — so moving the offset alone would update the one field
     * highlighting does not read and leave the ones it does describing the old
     * text. Where a quote appears more than once, that is how a highlight lands
     * on the wrong occurrence.
     *
     * A DQL update rather than loading the entity: this runs across every
     * comment in the database, and Comment::$anchor is readonly because
     * re-rendering is the only thing that legitimately moves one.
     */
    public function reanchor(string $commentId, Anchor $anchor, bool $orphaned): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.anchor.quote', ':quote')
            ->set('c.anchor.prefix', ':prefix')
            ->set('c.anchor.suffix', ':suffix')
            ->set('c.anchor.offsetHint', ':offsetHint')
            ->set('c.orphaned', ':orphaned')
            ->andWhere('c.id = :id')
            ->setParameter('quote', $anchor->quote)
            ->setParameter('prefix', $anchor->prefix)
            ->setParameter('suffix', $anchor->suffix)
            ->setParameter('offsetHint', $anchor->offsetHint)
            ->setParameter('orphaned', $orphaned)
            ->setParameter('id', $commentId, 'uuid')
            ->getQuery()
            ->execute();
    }

    /**
     * Anchored comments on one version, read inside the caller's transaction so
     * a comment created after a broader snapshot is not left behind with the old
     * rendering.
     *
     * @return list<array{id: string, anchor: Anchor, orphaned: bool}>
     */
    public function anchoredForVersion(string $versionId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT id, anchor_quote, anchor_prefix, anchor_suffix, anchor_offset_hint, orphaned
             FROM comments WHERE version_id = :version::uuid AND anchor_quote <> ''",
            ['version' => $versionId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'anchor' => new Anchor(
                    (string) $row['anchor_quote'],
                    (string) $row['anchor_prefix'],
                    (string) $row['anchor_suffix'],
                    (int) $row['anchor_offset_hint'],
                ),
                'orphaned' => (bool) $row['orphaned'],
            ],
            $rows,
        );
    }
}
