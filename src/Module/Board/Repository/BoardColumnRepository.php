<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BoardColumn>
 */
class BoardColumnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardColumn::class);
    }

    /** @return list<BoardColumn> */
    public function findForProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['position' => 'ASC']);
    }

    /**
     * The board as the database holds it now, for a writer that holds the
     * project lock. lock() leaves an already loaded column as the request read
     * it, and refresh() refuses to rewrite the readonly project, so the mutable
     * fields are read back by hand.
     *
     * @return list<BoardColumn>
     */
    public function findForProjectFresh(Project $project): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociativeIndexed(
            'SELECT id, label, slug, position, terminal, is_default FROM board_columns WHERE project_id = :projectId',
            ['projectId' => (string) $project->id],
        );

        $columns = [];
        foreach ($this->findForProject($project) as $column) {
            $row = $rows[(string) $column->id] ?? null;
            if (null === $row) {
                continue;
            }
            $column->label = (string) $row['label'];
            $column->slug = (string) $row['slug'];
            $column->position = (int) $row['position'];
            $column->terminal = (bool) $row['terminal'];
            $column->isDefault = (bool) $row['is_default'];
            $columns[] = $column;
        }

        return $columns;
    }

    /** The same lookup from a raw route parameter, for a MapEntity expression. */
    public function findDefaultForProjectId(string $projectId): ?BoardColumn
    {
        return Uuid::isValid($projectId)
            ? $this->findOneBy(['project' => Uuid::fromString($projectId), 'isDefault' => true])
            : null;
    }

    public function findFirstTerminalForProjectId(string $projectId): ?BoardColumn
    {
        return Uuid::isValid($projectId)
            ? $this->findOneBy(['project' => Uuid::fromString($projectId), 'terminal' => true], ['position' => 'ASC'])
            : null;
    }

    /** A URL that pairs one project with another project's column is a 404. */
    public function findOneByIdAndProjectId(string $columnId, string $projectId): ?BoardColumn
    {
        if (!Uuid::isValid($columnId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('k')
            ->andWhere('k.id = :columnId')
            ->andWhere('k.project = :projectId')
            ->setParameter('columnId', Uuid::fromString($columnId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
