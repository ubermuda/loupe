<?php

declare(strict_types=1);

namespace App\Module\Billing\Repository;

use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findOneByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    /** @return list<Subscription> trials past their end, not yet surveyed */
    public function findEndedTrialsToSurvey(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.kind = :kind')->setParameter('kind', SubscriptionKind::Trial)
            ->andWhere('s.endsAt IS NOT NULL AND s.endsAt < :now')->setParameter('now', $now)
            ->andWhere('s.surveySentAt IS NULL')
            ->getQuery()->getResult();
    }

    /** @return list<Subscription> canceled Stripe grants past their paid period, still needing a disable or a survey */
    public function findCanceledStripeSubscriptionsToSettle(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.billingProfile', 'p')
            ->join('p.user', 'u')
            ->andWhere('s.kind = :kind')->setParameter('kind', SubscriptionKind::Stripe)
            ->andWhere('s.stripeStatus = :canceled')->setParameter('canceled', BillingStatus::Canceled)
            ->andWhere('s.endsAt IS NOT NULL AND s.endsAt < :now')->setParameter('now', $now)
            ->andWhere('u.disabledAt IS NULL OR s.surveySentAt IS NULL')
            ->getQuery()->getResult();
    }
}
