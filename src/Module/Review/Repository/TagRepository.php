<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Normalises the name here rather than trusting the caller to: a lookup on a
     * raw string misses the stored row, and the find-or-create above it then
     * inserts a `Tag` whose constructor normalises to exactly that row's name.
     */
    public function findOneByProjectAndName(Project $project, string $name): ?Tag
    {
        return $this->findOneBy(['project' => $project, 'name' => Tag::normalizeName($name)]);
    }

    /**
     * Returns the project's tag of this name, creating it if no request has yet.
     *
     * The insert goes through DBAL rather than the ORM because two requests
     * coining the same new name would otherwise both miss the lookup, both queue
     * an insert, and the loser's `flush()` would throw — which **closes the
     * EntityManager**, so there is nothing left to retry into and the caller sees
     * a generic failure for a request that was entirely valid. Letting Postgres
     * settle it with `ON CONFLICT DO NOTHING` and then re-reading means both
     * requests end up on the same row.
     *
     * The conflict target is named on purpose. A bare `DO NOTHING` would also
     * swallow violations of constraints this method knows nothing about and
     * return as if it had succeeded; naming `(project_id, name)` keeps the
     * idempotency and lets anything unexpected still abort.
     *
     * The insert commits on its own, ahead of the caller's flush, so a flush that
     * then fails leaves a tag no document carries. That is the same state as a
     * tag whose last document dropped it, which `tag_list` already reports with a
     * count of zero.
     */
    public function findOrCreate(Project $project, string $name): Tag
    {
        $entityManager = $this->getEntityManager();

        // The ORM's own generator, so these ids cannot drift from the ones a
        // plain `persist()` of a Tag would have produced.
        $id = $entityManager->getClassMetadata(Tag::class)->idGenerator->generateId($entityManager, null);
        if (!$id instanceof Uuid) {
            throw new \LogicException('Tag ids are generated as UUIDs.');
        }

        $entityManager->getConnection()->executeStatement(
            'INSERT INTO tags (id, project_id, name, created_at) VALUES (:id, :project, :name, :createdAt)'
            .' ON CONFLICT (project_id, name) DO NOTHING',
            [
                'id' => (string) $id,
                'project' => (string) ($project->id ?? throw new \LogicException('a persisted project always has an id')),
                // Normalised before the SQL, because the constructor that would
                // otherwise have done it is not involved on this path.
                'name' => Tag::normalizeName($name),
                'createdAt' => new \DateTimeImmutable(),
            ],
            ['createdAt' => Types::DATETIME_IMMUTABLE],
        );

        return $this->findOneByProjectAndName($project, $name)
            ?? throw new \LogicException('the tag was just inserted or already existed');
    }

    /**
     * The project's whole vocabulary with how many documents carry each entry.
     *
     * Tags nobody uses are the point rather than noise: a name coined once and
     * never reused is exactly what a reader needs to see to notice the vocabulary
     * fragmenting, so the count query must not drop zero-document rows.
     *
     * Archived documents count. The question this answers is how widely a name is
     * used, not how many rows the documents list would show.
     *
     * @return list<array{tag: Tag, documentCount: int}>
     */
    public function findByProjectWithDocumentCounts(Project $project): array
    {
        $tags = $this->findBy(['project' => $project], ['name' => 'ASC']);

        // Counted in a second query rather than a left join off the tag, because
        // the join table has no inverse side to join from — and grouped by name
        // rather than id so the keys stay plain strings through array hydration.
        /** @var list<array{name: string, documentCount: int|numeric-string}> $rows */
        $rows = $this->getEntityManager()
            ->createQuery(
                // Both sides are constrained to the project. Only the tag side is
                // load-bearing today, but nothing in the schema forbids a join
                // row across projects, and a miscount would read as a real one.
                'SELECT t.name AS name, COUNT(d.id) AS documentCount'
                .' FROM '.Document::class.' d JOIN d.tags t'
                .' WHERE t.project = :project AND d.project = :project GROUP BY t.name',
            )
            ->setParameter('project', $project)
            ->getArrayResult();

        $counts = array_column($rows, 'documentCount', 'name');

        return array_map(
            static fn (Tag $tag): array => [
                'tag' => $tag,
                'documentCount' => (int) ($counts[$tag->name] ?? 0),
            ],
            $tags,
        );
    }
}
