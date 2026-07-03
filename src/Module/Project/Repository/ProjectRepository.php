<?php

declare(strict_types=1);

namespace App\Module\Project\Repository;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
