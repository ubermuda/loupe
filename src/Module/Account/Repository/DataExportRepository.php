<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DataExport> */
class DataExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataExport::class);
    }

    /** @return list<DataExport> */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['requestedAt' => 'DESC']);
    }

    public function findOnePendingByUser(User $user): ?DataExport
    {
        return $this->findOneBy(['user' => $user, 'status' => DataExportStatus::Pending]);
    }

    /** @return list<DataExport> */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.expiresAt IS NOT NULL')
            ->andWhere('e.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
