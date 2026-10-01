<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Project\Entity\Project;
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

    /** Locked and read fresh like findOneForBridgeLocked. The unique index allows one pending command per run. */
    public function findPendingForRunLocked(WorkerRun $run): ?BridgeCommand
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.workerRun = :run')
            ->andWhere('c.state = :pending')
            ->setParameter('run', $run)
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** Whether a stop of any run of the card still waits for its bridge. */
    public function hasPendingStopForCard(Project $project, Uuid $cardId): bool
    {
        return null !== $this->createQueryBuilder('c')
            ->select('1')
            ->join('c.workerRun', 'r')
            ->andWhere('c.project = :project')
            ->andWhere('r.cardId = :cardId')
            ->andWhere('c.kind = :stop')
            ->andWhere('c.state = :pending')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('stop', BridgeCommandKind::StopRun->value)
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Whether a stop of the card that was asked at or after the time still waits or was taken. */
    public function hasLiveStopForCardSince(Project $project, Uuid $cardId, \DateTimeImmutable $at): bool
    {
        return null !== $this->createQueryBuilder('c')
            ->select('1')
            ->join('c.workerRun', 'r')
            ->andWhere('c.project = :project')
            ->andWhere('r.cardId = :cardId')
            ->andWhere('c.kind = :stop')
            ->andWhere('c.state IN (:live)')
            ->andWhere('c.requestedAt >= :at')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('stop', BridgeCommandKind::StopRun->value)
            ->setParameter('live', [BridgeCommandState::Pending->value, BridgeCommandState::Done->value])
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Whether the newest stop of the run was withdrawn, so the run never stopped through it. */
    public function lastStopWasCancelled(WorkerRun $run): bool
    {
        $last = $this->createQueryBuilder('c')
            ->andWhere('c.workerRun = :run')
            ->andWhere('c.kind = :stop')
            ->setParameter('run', $run)
            ->setParameter('stop', BridgeCommandKind::StopRun->value)
            ->orderBy('c.requestedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $last instanceof BridgeCommand && BridgeCommandState::Cancelled === $last->state;
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

    /**
     * Every command of these runs, oldest request first.
     *
     * @param list<WorkerRun> $runs
     *
     * @return list<BridgeCommand>
     */
    public function findForRuns(array $runs): array
    {
        if ([] === $runs) {
            return [];
        }

        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.workerRun IN (:runs)')
            ->setParameter('runs', $runs)
            ->orderBy('c.requestedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The newest command of each run. On a tie of the request time, the higher id wins.
     *
     * @param list<WorkerRun> $runs
     *
     * @return array<string, BridgeCommand> keyed by the RFC 4122 run id
     */
    public function findLatestForRuns(array $runs): array
    {
        if ([] === $runs) {
            return [];
        }

        /** @var list<BridgeCommand> $commands */
        $commands = $this->createQueryBuilder('c')
            ->andWhere('c.workerRun IN (:runs)')
            ->andWhere('NOT EXISTS (SELECT n.id FROM '.BridgeCommand::class.' n WHERE n.workerRun = c.workerRun AND (n.requestedAt > c.requestedAt OR (n.requestedAt = c.requestedAt AND n.id > c.id)))')
            ->setParameter('runs', $runs)
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($commands as $command) {
            $latest[(string) $command->workerRun->id] = $command;
        }

        return $latest;
    }

    /** @return list<Project> the projects that hold a pending command whose time ran out */
    public function findProjectsWithDue(\DateTimeImmutable $now): array
    {
        return array_values($this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->andWhere('p IN (SELECT IDENTITY(c.project) FROM '.BridgeCommand::class.' c WHERE c.state = :pending AND c.expiresAt <= :now)')
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult());
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
