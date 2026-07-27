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

    /** @return Paginator<Document> */
    public function findPaginatedByProject(Project $project, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.project = :project')
            ->setParameter('project', $project)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb->getQuery());
    }

    /** @return list<Document> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByIdAndProject(Uuid $id, Project $project): ?Document
    {
        return $this->findOneBy(['id' => $id, 'project' => $project]);
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
