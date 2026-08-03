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
     * @param string $name already normalised by {@see Tag::normalizeName()}
     */
    public function findOneByProjectAndName(Project $project, string $name): ?Tag
    {
        return $this->findOneBy(['project' => $project, 'name' => $name]);
    }

    /**
     * The project's whole vocabulary with how many documents carry each entry.
     *
     * Tags nobody uses are the point rather than noise: a name coined once and
     * never reused is exactly what a reader needs to see to notice the vocabulary
     * fragmenting, so the count query must not drop zero-document rows.
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
                'SELECT t.name AS name, COUNT(d.id) AS documentCount'
                .' FROM '.Document::class.' d JOIN d.tags t'
                .' WHERE t.project = :project GROUP BY t.name',
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
