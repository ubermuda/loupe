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

    /** The column a card created with no column lands in. */
    public function findDefaultFor(Project $project): ?BoardColumn
    {
        return $this->findOneBy(['project' => $project, 'isDefault' => true]);
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
