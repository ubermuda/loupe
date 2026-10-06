<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRunFact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerRunFact>
 */
class WorkerRunFactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunFact::class);
    }

    /**
     * Every fact row on every project the user owns, for the account data
     * export. It includes the rows whose run the retention sweep deleted.
     *
     * @return list<WorkerRunFact>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('f')
            ->join('f.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('f.runId', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
