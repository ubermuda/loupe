<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerRunStateChange>
 */
class WorkerRunStateChangeRepository extends ServiceEntityRepository
{
    /** The order of WorkerRunState::rank(), so two states of one moment read in the order they happen. */
    private const string RANK = "CASE WHEN c.state = 'queued' THEN 0 WHEN c.state = 'resumed' THEN 1 WHEN c.state = 'running' THEN 2 ELSE 3 END";

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunStateChange::class);
    }

    /**
     * The run's states, oldest first. Two states can carry the same second, such
     * as a start and an end, so the state order breaks the tie, then arrival.
     *
     * @return list<WorkerRunStateChange>
     */
    public function findForRun(WorkerRun $run): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.run = :run')
            ->setParameter('run', $run)
            ->addSelect(self::RANK.' AS HIDDEN stateRank')
            ->orderBy('c.at', 'ASC')
            ->addOrderBy('stateRank', 'ASC')
            ->addOrderBy('c.receivedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The states of each run, in the order of findForRun(), with one query for
     * a whole page of runs.
     *
     * @param list<WorkerRun> $runs
     *
     * @return array<string, list<WorkerRunStateChange>> keyed by the run id
     */
    public function findForRuns(array $runs): array
    {
        if ([] === $runs) {
            return [];
        }

        /** @var list<WorkerRunStateChange> $changes */
        $changes = $this->createQueryBuilder('c')
            ->andWhere('c.run IN (:runs)')
            ->setParameter('runs', $runs)
            ->addSelect(self::RANK.' AS HIDDEN stateRank')
            ->orderBy('c.at', 'ASC')
            ->addOrderBy('stateRank', 'ASC')
            ->addOrderBy('c.receivedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byRun = [];
        foreach ($changes as $change) {
            $byRun[(string) $change->run->id][] = $change;
        }

        return $byRun;
    }

    /** @return list<WorkerRunState> the distinct states the run has held */
    public function statesOf(WorkerRun $run): array
    {
        /** @var list<string|WorkerRunState> $states */
        $states = $this->createQueryBuilder('c')
            ->select('DISTINCT c.state')
            ->andWhere('c.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(
            static fn (string|WorkerRunState $state): WorkerRunState => $state instanceof WorkerRunState ? $state : WorkerRunState::from($state),
            $states,
        );
    }

    /**
     * Every state of every run on every project the user owns, for the account
     * data export, in the order of findForRun() within each run.
     *
     * @return list<WorkerRunStateChange>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('c')
            ->join('c.run', 'r')
            ->join('r.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->addSelect(self::RANK.' AS HIDDEN stateRank')
            ->orderBy('c.at', 'ASC')
            ->addOrderBy('stateRank', 'ASC')
            ->addOrderBy('c.receivedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
