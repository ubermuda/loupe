<?php

declare(strict_types=1);

namespace App\Module\Billing\Repository;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
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

    /** @return list<BillingProfile> trials past their end, never subscribed */
    public function findExpiredTrials(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')->setParameter('status', BillingStatus::Trialing)
            ->andWhere('p.trialEndsAt < :now')->setParameter('now', $now)
            ->getQuery()->getResult();
    }

    /** @return list<BillingProfile> subscribers whose trial window has closed, not yet surveyed */
    public function findTrialEndedSubscribers(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status IN (:statuses)')->setParameter('statuses', [BillingStatus::Active, BillingStatus::PastDue])
            ->andWhere('p.trialEndsAt < :now')->setParameter('now', $now)
            ->andWhere('p.surveySentAt IS NULL')
            ->getQuery()->getResult();
    }

    /** @return list<BillingProfile> canceled, paid period over, still needing a disable or a survey */
    public function findCanceledPastPeriod(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.user', 'u')
            ->andWhere('p.status = :status')->setParameter('status', BillingStatus::Canceled)
            ->andWhere('p.currentPeriodEnd IS NULL OR p.currentPeriodEnd < :now')->setParameter('now', $now)
            ->andWhere('u.disabledAt IS NULL OR p.cancelSurveySentAt IS NULL')
            ->getQuery()->getResult();
    }
}
