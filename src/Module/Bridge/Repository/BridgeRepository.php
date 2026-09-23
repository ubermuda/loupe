<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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
