<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bridge>
 */
class BridgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bridge::class);
    }

    /** @return list<Bridge> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['lastSeenAt' => 'DESC']);
    }
}
