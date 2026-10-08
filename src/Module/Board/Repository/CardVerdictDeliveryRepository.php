<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardVerdictDelivery> */
class CardVerdictDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardVerdictDelivery::class);
    }

    /**
     * The deliveries of those verdicts with the pull request loaded, keyed by verdict id.
     *
     * @param list<CardVerdict> $verdicts
     *
     * @return array<string, list<CardVerdictDelivery>>
     */
    public function findForVerdicts(array $verdicts): array
    {
        if ([] === $verdicts) {
            return [];
        }

        /** @var list<CardVerdictDelivery> $deliveries */
        $deliveries = $this->createQueryBuilder('d')
            ->addSelect('pr')
            ->join('d.pullRequest', 'pr')
            ->andWhere('d.verdict IN (:verdicts)')
            ->setParameter('verdicts', $verdicts)
            ->orderBy('pr.repository', 'ASC')
            ->addOrderBy('pr.number', 'ASC')
            ->getQuery()
            ->getResult();

        $byVerdict = [];
        foreach ($deliveries as $delivery) {
            $byVerdict[(string) $delivery->verdict->id][] = $delivery;
        }

        return $byVerdict;
    }

    /**
     * The refused deliveries of one reviewer that carry that reason, for cards outside a terminal column.
     *
     * @return list<CardVerdictDelivery>
     */
    public function findRefusedForReviewer(User $reviewer, string $reason): array
    {
        return array_values($this->createQueryBuilder('d')
            ->join('d.verdict', 'v')
            ->join('v.card', 'card')
            ->join('card.column', 'k')
            ->andWhere('v.reviewer = :reviewer')
            ->andWhere('d.state = :refused')
            ->andWhere('d.reason = :reason')
            ->andWhere('k.terminal = false')
            ->setParameter('reviewer', $reviewer)
            ->setParameter('refused', CardVerdictDeliveryState::Refused)
            ->setParameter('reason', $reason)
            ->getQuery()
            ->getResult());
    }
}
