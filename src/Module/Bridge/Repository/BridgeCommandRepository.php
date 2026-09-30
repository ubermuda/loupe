<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeCommand>
 */
class BridgeCommandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeCommand::class);
    }

    /**
     * The commands one bridge has still to act on, oldest first.
     *
     * @return list<BridgeCommand>
     */
    public function findPendingFor(User $owner, Uuid $bridgeId, \DateTimeImmutable $now): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.owner = :owner')
            ->andWhere('c.bridgeId = :bridgeId')
            ->andWhere('c.state = :pending')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('owner', $owner)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('c.requestedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Locked until the transaction ends, and read fresh even when the command
     * is already managed, because the expiry sweep writes by bulk update.
     */
    public function findOneForBridgeLocked(User $owner, Uuid $bridgeId, Uuid $id): ?BridgeCommand
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.owner = :owner')
            ->andWhere('c.bridgeId = :bridgeId')
            ->andWhere('c.id = :id')
            ->setParameter('owner', $owner)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** Reads the state alone, like the unique index, so a pending command past its expiry still counts until the sweep runs. */
    public function hasPendingForRun(WorkerRun $run): bool
    {
        return null !== $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.workerRun = :run')
            ->andWhere('c.state = :pending')
            ->setParameter('run', $run)
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Moves every pending command whose time ran out to expired, and answers how many moved. */
    public function expireDue(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('c')
            ->update()
            ->set('c.state', ':expired')
            ->set('c.settledAt', ':now')
            ->andWhere('c.state = :pending')
            ->andWhere('c.expiresAt <= :now')
            ->setParameter('expired', BridgeCommandState::Expired->value)
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    /**
     * The commands on the user's bridges, and the commands the user asked for.
     *
     * @return list<BridgeCommand>
     */
    public function findByOwnerOrRequester(User $user): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.owner = :user OR c.requestedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('c.requestedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
