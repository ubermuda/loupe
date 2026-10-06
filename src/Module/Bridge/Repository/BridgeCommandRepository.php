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

    /**
     * Locked and read fresh like findOneForBridgeLocked. A run has one bridge, so
     * the unique index allows one such command. A usage collection can wait on
     * several bridges at once, and a person never cancels it.
     */
    public function findPendingForRunLocked(WorkerRun $run): ?BridgeCommand
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.workerRun = :run')
            ->andWhere('c.state = :pending')
            ->andWhere('c.kind != :collect')
            ->setParameter('run', $run)
            ->setParameter('pending', BridgeCommandState::Pending->value)
            ->setParameter('collect', BridgeCommandKind::CollectSessionUsage->value)
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

    /** Whether anyone asked to resume the run at or after the time. */
    public function hasResumeOfRunSince(WorkerRun $run, \DateTimeImmutable $since): bool
    {
        return null !== $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.workerRun = :run')
            ->andWhere('c.kind = :resume')
            ->andWhere('c.requestedAt >= :since')
            ->setParameter('run', $run)
            ->setParameter('resume', BridgeCommandKind::ResumeRun->value)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
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
