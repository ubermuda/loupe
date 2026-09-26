<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerRunUsage>
 */
class WorkerRunUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunUsage::class);
    }

    /** A DQL delete runs at once, so new rows for the same models can follow in one flush. */
    public function deleteForRun(WorkerRun $run): void
    {
        $this->createQueryBuilder('u')
            ->delete()
            ->andWhere('u.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->execute();
    }

    /**
     * Every usage row on every project the user owns, for the account data
     * export. It includes the rows whose run the retention sweep deleted.
     *
     * @return list<WorkerRunUsage>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('u')
            ->join('u.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
