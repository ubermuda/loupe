<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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

    public function findOneByProjectAndSlug(Project $project, string $slug): ?BoardColumn
    {
        return $this->findOneBy(['project' => $project, 'slug' => $slug]);
    }
}
