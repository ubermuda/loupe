<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /** @return list<Document> */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['createdAt' => 'DESC']);
    }

    /**
     * Archived documents are excluded unless asked for: they stay reachable at
     * their own URL, but a list is the one place they are meant to leave.
     *
     * @return Paginator<Document>
     */
    public function findPaginatedByProject(Project $project, int $page, int $perPage, bool $includeArchived = false): Paginator
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.project = :project')
            ->setParameter('project', $project)
            // created_at is TIMESTAMP(0), so same-second rows tie; without a
            // unique tiebreak, offset pages can repeat or skip a document.
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (!$includeArchived) {
            $qb->andWhere('d.archivedAt IS NULL');
        }

        return new Paginator($qb->getQuery());
    }

    /**
     * Hydrates the tag collections of a page of documents in one query, so
     * rendering the list does not fire one per row. Nothing is returned: the
     * collections are populated on the Document instances the caller already has.
     *
     * @param list<Document> $documents
     */
    public function preloadTags(array $documents): void
    {
        if ([] === $documents) {
            return;
        }

        $this->createQueryBuilder('d')
            ->addSelect('t')
            ->leftJoin('d.tags', 't')
            ->andWhere('d IN (:documents)')
            ->setParameter('documents', $documents)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Document> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }

    /**
     * How many documents a reader would find in the project's list — archived
     * ones excluded, because a count that disagrees with the rows under it reads
     * as a bug. Anything that must account for every document that exists (an
     * export, a quota) counts its own way rather than calling this.
     */
    public function countActiveByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.project = :project')
            ->andWhere('d.archivedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Route-binding lookup: both ids arrive as raw strings from the router
     * (EntityValueResolver expr variables are never entities).
     */
    public function findOneByIdAndProjectId(string $id, string $projectId): ?Document
    {
        try {
            return $this->findOneBy(['id' => Uuid::fromString($id), 'project' => Uuid::fromString($projectId)]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
