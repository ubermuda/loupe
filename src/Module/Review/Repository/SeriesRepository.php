<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Series>
 */
class SeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Series::class);
    }

    /**
     * Matches on the comparison key rather than the stored spelling, so a caller
     * that writes "Rust Atomics" finds the series somebody coined as "rust
     * atomics".
     */
    public function findOneByProjectAndName(Project $project, string $name): ?Series
    {
        return $this->findOneBy(['project' => $project, 'normalizedName' => Series::normalizeName($name)]);
    }

    /**
     * Returns the project's series of this name, creating it if no request has yet.
     *
     * The insert goes through DBAL rather than the ORM because two requests
     * coining the same new name would otherwise both miss the lookup, both queue
     * an insert, and the loser's `flush()` would throw — which closes the
     * EntityManager, so there is nothing left to retry into. Letting Postgres
     * settle it with `ON CONFLICT DO NOTHING` and re-reading means both requests
     * end up on the same row.
     *
     * The conflict target is named on purpose. A bare `DO NOTHING` would also
     * swallow violations of constraints this method knows nothing about.
     *
     * The first spelling wins, because `DO NOTHING` keeps the row already
     * there. A document created later therefore cannot re-title a series every
     * reader already sees; `series_rename` is the deliberate way to change it.
     *
     * With no caller transaction open the insert commits on its own, ahead of
     * the caller's flush, so a flush that then fails leaves a series no
     * document belongs to. That is the same state as a series whose last
     * document left it, which `series_list` already reports with a count of
     * zero. Inside a caller's transaction, such as a revision, it joins that
     * transaction and rolls back with it instead.
     */
    public function findOrCreate(Project $project, string $name): Series
    {
        $entityManager = $this->getEntityManager();

        // The ORM's own generator, so these ids cannot drift from the ones a
        // plain `persist()` of a Series would have produced.
        $id = $entityManager->getClassMetadata(Series::class)->idGenerator->generateId($entityManager, null);
        if (!$id instanceof Uuid) {
            throw new \LogicException('Series ids are generated as UUIDs.');
        }

        $entityManager->getConnection()->executeStatement(
            'INSERT INTO series (id, project_id, name, normalized_name, created_at)'
            .' VALUES (:id, :project, :name, :normalizedName, :createdAt)'
            .' ON CONFLICT (project_id, normalized_name) DO NOTHING',
            [
                'id' => (string) $id,
                'project' => (string) ($project->id ?? throw new \LogicException('a persisted project always has an id')),
                // Normalised before the SQL, because the constructor that would
                // otherwise have done it is not involved on this path.
                'name' => Series::normalizeDisplayName($name),
                'normalizedName' => Series::normalizeName($name),
                'createdAt' => new \DateTimeImmutable(),
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return $this->findOneByProjectAndName($project, $name)
            ?? throw new \LogicException('the series was just inserted or already existed');
    }

    /**
     * Ordered by the comparison key rather than the spelling, so the order does
     * not move when somebody renames a series only to change its case.
     *
     * @return list<Series>
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['normalizedName' => 'ASC']);
    }

    /**
     * The project's series with how many documents each holds, and the highest
     * ordinal in use.
     *
     * The highest ordinal is what an agent adding to a series needs: it says
     * which number comes next without paging the document list. It is null for a
     * series nothing belongs to.
     *
     * Archived documents count. The question this answers is how far a series
     * runs, not how many rows the documents list would show.
     *
     * @return list<array{series: Series, documentCount: int, highestOrdinal: ?int}>
     */
    public function findByProjectWithDocumentCounts(Project $project): array
    {
        $series = $this->findByProject($project);

        // A second query rather than a left join off the series, so a series no
        // document belongs to still reports zero instead of dropping out.
        // Grouped on the comparison key, which is unique per project, so the
        // keys stay plain strings through array hydration.
        /** @var list<array{normalizedName: string, documentCount: int|numeric-string, highestOrdinal: int|numeric-string|null}> $rows */
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT s.normalizedName AS normalizedName, COUNT(d.id) AS documentCount, MAX(d.seriesOrdinal) AS highestOrdinal'
                .' FROM '.Document::class.' d JOIN d.series s'
                .' WHERE s.project = :project AND d.project = :project GROUP BY s.normalizedName',
            )
            ->setParameter('project', $project)
            ->getArrayResult();

        $counts = array_column($rows, 'documentCount', 'normalizedName');
        $highest = array_column($rows, 'highestOrdinal', 'normalizedName');

        return array_map(
            static fn (Series $one): array => [
                'series' => $one,
                'documentCount' => (int) ($counts[$one->normalizedName] ?? 0),
                'highestOrdinal' => isset($highest[$one->normalizedName]) ? (int) $highest[$one->normalizedName] : null,
            ],
            $series,
        );
    }
}
