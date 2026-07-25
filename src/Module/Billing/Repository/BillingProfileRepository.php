<?php

declare(strict_types=1);

namespace App\Module\Billing\Repository;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingProfile>
 */
class BillingProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingProfile::class);
    }

    public function findOneByUser(User $user): ?BillingProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByStripeCustomerId(string $customerId): ?BillingProfile
    {
        return $this->findOneBy(['stripeCustomerId' => $customerId]);
    }
}
