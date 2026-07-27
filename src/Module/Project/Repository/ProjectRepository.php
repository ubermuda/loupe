<?php

declare(strict_types=1);

namespace App\Module\Project\Repository;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return list<Project> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }

    /** @return Paginator<Project> */
    public function findPaginatedByOwner(User $owner, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb->getQuery());
    }

    /**
     * The owner's very first project — used to gate the first-run wizard.
     */
    public function findOldestByOwner(User $owner): ?Project
    {
        return $this->findOneBy(['owner' => $owner], ['createdAt' => 'ASC']);
    }

    public function findOneByOwnerAndName(User $owner, string $name): ?Project
    {
        return $this->findOneBy(['owner' => $owner, 'name' => $name]);
    }

    public function findOneByWidgetToken(ApiToken $token): ?Project
    {
        return $this->findOneBy(['widgetToken' => $token]);
    }

    public function findOneByMcpToken(ApiToken $token): ?Project
    {
        return $this->findOneBy(['mcpToken' => $token]);
    }

    /**
     * Whether the project's row currently has a widget token, read straight from
     * the database (bypassing the identity map). Used after a pessimistic lock
     * so a concurrent mint's committed change is visible — entity refresh() is
     * not an option here, it fails on the readonly $createdAt property.
     */
    public function hasCommittedWidgetToken(Project $project): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('IDENTITY(p.widgetToken)')
            ->where('p = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether the project's row currently has an MCP token, read straight from
     * the database (bypassing the identity map). Used after a pessimistic lock
     * so a concurrent mint's committed change is visible — entity refresh() is
     * not an option here, it fails on the readonly $createdAt property.
     */
    public function hasCommittedMcpToken(Project $project): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('IDENTITY(p.mcpToken)')
            ->where('p = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Resolves a project from a user-supplied handle: a uuid or the project name.
     * Owner-scoped — never returns another user's project.
     */
    public function findOneByIdOrNameForOwner(string $handle, User $owner): ?Project
    {
        // A handle can be both UUID-shaped and a legitimate project name, and an
        // id miss must still fall through to the name lookup — so never let a
        // successful parse short-circuit the fallback.
        try {
            $id = Uuid::fromString($handle);
        } catch (\InvalidArgumentException) {
            $id = null;
        }

        if (null !== $id) {
            $project = $this->findOneBy(['id' => $id, 'owner' => $owner]);
            if (null !== $project) {
                return $project;
            }
        }

        return $this->findOneByOwnerAndName($owner, $handle);
    }
}
