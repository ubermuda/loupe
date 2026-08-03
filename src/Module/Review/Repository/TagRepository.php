<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
