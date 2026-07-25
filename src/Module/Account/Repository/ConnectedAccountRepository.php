<?php

declare(strict_types=1);

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConnectedAccount>
 */
class ConnectedAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnectedAccount::class);
    }

    public function findOneByProviderAndProviderUserId(SocialProvider $provider, string $providerUserId): ?ConnectedAccount
    {
        return $this->findOneBy(['provider' => $provider, 'providerUserId' => $providerUserId]);
    }
}
