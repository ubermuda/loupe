<?php

declare(strict_types=1);

namespace App\Module\Billing\Repository;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BetaInvite>
 */
class BetaInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BetaInvite::class);
    }

    /** Any state: the caller decides whether a used or revoked invite counts. */
    public function findOneByToken(string $token): ?BetaInvite
    {
        return $this->findOneBy(['tokenHash' => BetaInvite::hashToken($token)]);
    }

    public function findOneRedeemedBy(User $user): ?BetaInvite
    {
        return $this->findOneBy(['redeemedBy' => $user]);
    }
}
