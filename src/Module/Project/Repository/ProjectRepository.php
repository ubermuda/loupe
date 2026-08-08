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

    /**
     * The owner's newest projects, for a picker that offers a way to see them all.
     *
     * Bounded because it is built into every project-scoped page whether or not
     * the panel is ever opened.
     *
     * `created_at` is TIMESTAMP(0), so same-second rows tie and the cut would
     * otherwise fall in a different place run to run. The id breaks it
     * chronologically rather than arbitrarily: these are UUIDv7, whose leading
     * bits are a millisecond timestamp, and Symfony's generator keeps a counter
     * so two minted in the same millisecond still ascend.
     *
     * @return list<Project>
     */
    public function findNewestByOwner(User $owner, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit);

        return array_values($qb->getQuery()->getResult());
    }

    /** @return Paginator<Project> */
    public function findPaginatedByOwner(User $owner, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            // created_at is TIMESTAMP(0), so same-second rows tie; without a
            // unique tiebreak, offset pages can repeat or skip a project.
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
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

    public function hasCommittedWidgetToken(Project $project): bool
    {
        return null !== $this->committedWidgetTokenId($project);
    }

    public function hasCommittedMcpToken(Project $project): bool
    {
        return null !== $this->committedMcpTokenId($project);
    }

    /**
     * The widget token id on the project's row, read straight from the database.
     * {@see self::committedTokenId()} for why this exists.
     */
    public function committedWidgetTokenId(Project $project): ?string
    {
        return $this->committedTokenId($project, 'widgetToken');
    }

    /** The MCP token id on the project's row. {@see self::committedTokenId()}. */
    public function committedMcpTokenId(Project $project): ?string
    {
        return $this->committedTokenId($project, 'mcpToken');
    }

    /**
     * Reads a token association straight from the row, bypassing the identity
     * map. Used after a pessimistic lock so a concurrent write's committed
     * change is visible: the caller's in-memory Project still carries whatever
     * the association held when it was loaded, which for a regeneration means a
     * token another transaction has already deleted.
     *
     * entity refresh() is not an option here — it fails on the readonly
     * $createdAt property.
     *
     * @param 'mcpToken'|'widgetToken' $association
     */
    private function committedTokenId(Project $project, string $association): ?string
    {
        $id = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.'.$association.')')
            ->where('p = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null !== $id ? (string) $id : null;
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
