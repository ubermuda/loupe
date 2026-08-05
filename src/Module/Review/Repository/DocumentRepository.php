<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Doctrine\FullTextSearch;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
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
     * The filter arguments are optional because the MCP document_list tool shares
     * this method and exposes none of them.
     *
     * @param string|null $tagName already normalised by {@see Tag::normalizeName()}
     *
     * @return Paginator<Document>
     */
    public function findPaginatedByProject(
        Project $project,
        int $page,
        int $perPage,
        bool $includeArchived = false,
        ?string $search = null,
        ?DocumentStatus $status = null,
        ?string $tagName = null,
    ): Paginator {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.project = :project')
            ->setParameter('project', $project)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (!$includeArchived) {
            $qb->andWhere('d.archivedAt IS NULL');
        }

        // A plain WHERE: the status is a column on this table, not a relation.
        if (null !== $status) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status);
        }

        if (null !== $tagName) {
            $qb->innerJoin('d.tags', 'filterTag')
                ->andWhere('filterTag.name = :tagName')
                ->setParameter('tagName', $tagName);
        }

        if (null !== $search) {
            // The configuration is concatenated rather than bound: Postgres
            // overloads websearch_to_tsquery as (regconfig, text) and (text), so
            // a bound parameter has no type to resolve against and picks the
            // wrong arity. It is a class constant, never user input.
            $tsquery = \sprintf("WEBSEARCH_TO_TSQUERY('%s', :search)", FullTextSearch::CONFIGURATION);

            $qb->andWhere(\sprintf('TSMATCH(d.searchVector, %s) = true', $tsquery))
                ->setParameter('search', $search)
                ->orderBy(\sprintf('TS_RANK(d.searchVector, %s)', $tsquery), 'DESC');
        } else {
            $qb->orderBy('d.createdAt', 'DESC');
        }

        // created_at is TIMESTAMP(0) so same-second rows tie, and ranks tie far
        // more often still; without a unique tiebreak, offset pages can repeat or
        // skip a document.
        $qb->addOrderBy('d.id', 'DESC');

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
        $this->preloadCollection($documents, 'tags');
    }

    /**
     * The same, for versions. Wanted by the data export, which reads every
     * version of every document the user owns.
     *
     * @param list<Document> $documents
     */
    public function preloadVersions(array $documents): void
    {
        $this->preloadCollection($documents, 'versions');
    }

    /**
     * The same, for outgoing references. Wanted by the data export, which reads
     * every document's references while assembling its row.
     *
     * @param list<Document> $documents
     */
    public function preloadReferences(array $documents): void
    {
        $this->preloadCollection($documents, 'references');
    }

    /**
     * Kept to one query per association rather than one fetch-join carrying all
     * of them: joining several collections at once multiplies their rows
     * together, so a document with 3 versions and 4 tags would hydrate from 12.
     *
     * @param list<Document>                 $documents
     * @param 'references'|'tags'|'versions' $association
     */
    private function preloadCollection(array $documents, string $association): void
    {
        if ([] === $documents) {
            return;
        }

        $this->createQueryBuilder('d')
            ->addSelect('c')
            ->leftJoin('d.'.$association, 'c')
            ->andWhere('d IN (:documents)')
            ->setParameter('documents', $documents)
            ->getQuery()
            ->getResult();
    }

    /**
     * The project is fetch-joined because DocumentExporter reads
     * `$document->project->name` per row: as a ManyToOne it is a proxy, so
     * without this the first read of each distinct project costs its own SELECT.
     *
     * @return list<Document>
     */
    public function findByOwner(User $owner): array
    {
        /** @var list<Document> $documents */
        $documents = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->join('d.project', 'p')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $documents;
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
     * Reads the stored archive state back, past the loaded entity.
     *
     * A caller holding a row lock needs to see what a racing transaction
     * committed, and `Document` cannot be refreshed — Doctrine refuses to
     * rewrite its readonly `$createdAt` — so the two columns are selected on
     * their own instead.
     *
     * @return array{archivedAt: ?\DateTimeImmutable, archiveReason: ?string}
     */
    public function archiveStateOf(Document $document): array
    {
        /** @var array{archivedAt: ?\DateTimeImmutable, archiveReason: ?string} $state */
        $state = $this->createQueryBuilder('d')
            ->select('d.archivedAt', 'd.archiveReason')
            ->andWhere('d = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleResult();

        return $state;
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
