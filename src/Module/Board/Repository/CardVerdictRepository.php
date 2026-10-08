<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardVerdict> */
class CardVerdictRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardVerdict::class);
    }

    public function findLatestForCard(Card $card): ?CardVerdict
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.card = :card')
            ->setParameter('card', $card)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CardVerdict> */
    public function findByReviewer(User $reviewer): array
    {
        return array_values($this->createQueryBuilder('v')
            ->addSelect('card')
            ->join('v.card', 'card')
            ->andWhere('v.reviewer = :reviewer')
            ->setParameter('reviewer', $reviewer)
            ->orderBy('v.createdAt', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<CardVerdict> */
    public function findForCard(Card $card): array
    {
        return array_values($this->createQueryBuilder('v')
            ->andWhere('v.card = :card')
            ->setParameter('card', $card)
            ->orderBy('v.createdAt', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * How many of those notes are still pending. A deleted note is not.
     *
     * @param list<string> $noteIds
     */
    public function countPendingNotes(array $noteIds): int
    {
        if ([] === $noteIds) {
            return 0;
        }

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(SiteReviewComment::class, 'c')
            ->andWhere('c.id IN (:ids)')
            ->andWhere('c.status = :pending')
            ->setParameter('ids', $noteIds, ArrayParameterType::STRING)
            ->setParameter('pending', SiteReviewCommentStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
