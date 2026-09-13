<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerRun>
 */
class WorkerRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRun::class);
    }

    /**
     * Every run on every project the user owns, for the account data export.
     *
     * @return list<WorkerRun>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('r')
            ->join('r.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('r.receivedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Deletes every run the server received before the given moment, and answers
     * how many rows went.
     *
     * The cut is on the server clock rather than on the bridge clock. A bridge
     * with a wrong clock would otherwise stamp a run outside the window and lose
     * it on the next sweep.
     */
    public function deleteReceivedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.receivedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
