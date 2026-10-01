<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Bridge>
 */
class BridgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bridge::class);
    }

    /**
     * Serialises the writers of one bridge of one owner until the transaction
     * ends: two first heartbeats, and a heartbeat against the timeout sweep.
     */
    public function lockForWrite(string $ownerId, Uuid $bridgeId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['bridge:'.$ownerId.':'.$bridgeId->toRfc4122()],
        );
    }

    public function findOneByOwnerAndId(User $owner, Uuid $id): ?Bridge
    {
        return $this->findOneBy(['owner' => $owner, 'id' => $id]);
    }

    /** The bridge of the project owner, and only while it follows the project. */
    public function findOneFollowingProject(string $projectId, string $bridgeId): ?Bridge
    {
        if (!Uuid::isValid($projectId) || !Uuid::isValid($bridgeId)) {
            return null;
        }

        $bridge = $this->createQueryBuilder('b')
            ->innerJoin(Project::class, 'p', 'WITH', 'p.owner = b.owner')
            ->andWhere('p.id = :projectId')
            ->andWhere('b.id = :bridgeId')
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->setParameter('bridgeId', Uuid::fromString($bridgeId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $bridge instanceof Bridge && \in_array(Uuid::fromString($projectId)->toRfc4122(), $bridge->projects, true) ? $bridge : null;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<Bridge>
     */
    public function findByOwnerAndIds(User $owner, array $ids): array
    {
        return $this->findBy(['owner' => $owner, 'id' => $ids]);
    }

    /** @return list<Bridge> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['lastSeenAt' => 'DESC']);
    }
}
