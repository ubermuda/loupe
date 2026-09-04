<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Series;
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

    /** How many documents the series holds, archived ones included. */
    public function countBySeries(Series $series): int
    {
        return $this->count(['series' => $series]);
    }

    /** The document already at this place in this series, if any. */
    public function findOneInSeriesAt(Series $series, int $ordinal): ?Document
    {
        return $this->findOneBy(['series' => $series, 'seriesOrdinal' => $ordinal]);
    }

    /**
     * Archived documents are excluded unless asked for: they stay reachable at
     * their own URL, but a list is the one place they are meant to leave.
     *
     * The filter arguments are optional because the MCP document_list tool shares
     * this method and exposes none of them.
     *
     * @param string|null $tagName    already normalised by {@see Tag::normalizeName()}
     * @param string|null $seriesName already normalised by {@see Series::normalizeName()}
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
        ?string $seriesName = null,
    ): Paginator {
        $qb = $this->createQueryBuilder('d')
            // A to-one join, so it multiplies no rows and spares the list and
            // the MCP tool a query per document.
            ->leftJoin('d.series', 'documentSeries')
            ->addSelect('documentSeries')
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

        if (null !== $seriesName) {
            $qb->innerJoin('d.series', 'filterSeries')
                ->andWhere('filterSeries.normalizedName = :seriesName')
                ->setParameter('seriesName', $seriesName);
        }

        if (null !== $search) {
            // One branch per language the project holds, each with a constant
            // configuration, because Postgres uses the GIN index only when the
            // tsquery is the same for every row. Deriving the configuration from
            // the row instead turns the match into a filter over every document
            // in the project.
            $branches = [];
            foreach ($this->searchLanguagesOf($project) as $index => $language) {
                // The configuration is concatenated rather than bound: Postgres
                // overloads websearch_to_tsquery as (regconfig, text) and (text),
                // so a bound parameter has no type to resolve against and picks
                // the wrong arity. It comes from the enum, never from user input.
                $branches[] = \sprintf(
                    "(d.searchLanguage = :searchLanguage%d AND TSMATCH(d.searchVector, WEBSEARCH_TO_TSQUERY('%s', :search)) = true)",
                    $index,
                    $language->value,
                );
                $qb->setParameter('searchLanguage'.$index, $language);
            }

            $qb->andWhere('('.implode(' OR ', $branches).')')
                ->setParameter('search', $search);
        }

        // One series is an ordered set, so its own numbering outranks both
        // recency and search rank once the reader has asked for it.
        if (null !== $seriesName) {
            $qb->orderBy('d.seriesOrdinal', 'ASC');
        } elseif (null !== $search) {
            // The rank runs on the matches only, so the per-row cast costs
            // nothing here. CAST because websearch_to_tsquery has no
            // (varchar, text) overload, only (regconfig, text).
            $qb->orderBy('TS_RANK(d.searchVector, WEBSEARCH_TO_TSQUERY(CAST(d.searchLanguage AS regconfig), :search))', 'DESC');
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
     * The distinct languages the project's documents are stemmed in. An
     * index-only scan of idx_documents_project_search_language, which is why the
     * index exists.
     *
     * A project with no documents answers with the default, so the search query
     * always has one branch to build. It matches nothing either way.
     *
     * @return list<SearchLanguage>
     */
    public function searchLanguagesOf(Project $project): array
    {
        /** @var list<SearchLanguage|string> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.searchLanguage')
            ->andWhere('d.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleColumnResult();

        $languages = array_map(
            static fn (SearchLanguage|string $row): SearchLanguage => $row instanceof SearchLanguage ? $row : SearchLanguage::from($row),
            $rows,
        );

        return [] === $languages ? [SearchLanguage::DEFAULT] : $languages;
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
     * The project and the series are fetch-joined because DocumentExporter
     * reads `$document->project->name` and `$document->series->name` per row: as
     * ManyToOne associations they are proxies, so without this the first read of
     * each distinct one costs its own SELECT.
     *
     * @return list<Document>
     */
    public function findByOwner(User $owner): array
    {
        /** @var list<Document> $documents */
        $documents = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->addSelect('s')
            ->join('d.project', 'p')
            ->leftJoin('d.series', 's')
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
     * Same count as countActiveByProject, for several projects in one query —
     * the shell's project switcher lists every project on every page, so the
     * per-project form would be an N+1 on all of them.
     *
     * @param list<Project> $projects
     *
     * @return array<string, int> project id => count, projects with none omitted
     */
    public function countActiveByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.project) AS id, COUNT(d.id) AS total')
            ->andWhere('d.project IN (:projects)')
            ->andWhere('d.archivedAt IS NULL')
            ->setParameter('projects', $projects)
            ->groupBy('d.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
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
