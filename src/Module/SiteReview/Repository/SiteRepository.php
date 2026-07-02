<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    /** @return list<Site> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC']);
    }

    public function findOneByOwnerAndName(User $owner, string $name): ?Site
    {
        return $this->findOneBy(['owner' => $owner, 'name' => $name]);
    }

    public function findOneByToken(ApiToken $token): ?Site
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Resolves a site from a user-supplied handle: a uuid or the site name.
     * Owner-scoped — never returns another user's site.
     */
    public function findOneByIdOrNameForOwner(string $handle, User $owner): ?Site
    {
        // A handle can be both UUID-shaped and a legitimate site name, and an
        // id miss must still fall through to the name lookup — so never let a
        // successful parse short-circuit the fallback.
        try {
            $id = Uuid::fromString($handle);
        } catch (\InvalidArgumentException) {
            $id = null;
        }

        if (null !== $id) {
            $site = $this->findOneBy(['id' => $id, 'owner' => $owner]);
            if (null !== $site) {
                return $site;
            }
        }

        return $this->findOneByOwnerAndName($owner, $handle);
    }
}
