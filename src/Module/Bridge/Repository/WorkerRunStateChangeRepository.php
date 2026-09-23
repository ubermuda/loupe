<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerRunStateChange>
 */
class WorkerRunStateChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunStateChange::class);
    }

    /**
     * The run's states, oldest first. Two reports can carry the same moment,
     * such as the start and the end of a spawn failure, so arrival breaks the tie.
     *
     * @return list<WorkerRunStateChange>
     */
    public function findForRun(WorkerRun $run): array
    {
        return array_values($this->createQueryBuilder('c')
            ->andWhere('c.run = :run')
            ->setParameter('run', $run)
            ->orderBy('c.at', 'ASC')
            ->addOrderBy('c.receivedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
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
            ->orderBy('c.at', 'ASC')
            ->addOrderBy('c.receivedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
