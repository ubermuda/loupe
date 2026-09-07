<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardSiteReviewComment>
 */
class CardSiteReviewCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardSiteReviewComment::class);
    }

    /**
     * One card's comments, oldest first, with the comment already loaded.
     *
     * @return list<CardSiteReviewComment>
     */
    public function findForCard(Card $card): array
    {
        /* @var list<CardSiteReviewComment> */
        return $this->createQueryBuilder('l')
            ->addSelect('c')
            ->join('l.comment', 'c')
            ->where('l.card = :card')
            ->setParameter('card', $card)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The comments of many cards at once, keyed by card id, oldest first.
     *
     * One query for a page of cards. Reading a board of 40 cards one card at a
     * time is the shape this exists to avoid.
     *
     * @param list<Card> $cards
     *
     * @return array<string, list<CardSiteReviewComment>>
     */
    public function findForCards(array $cards): array
    {
        if ([] === $cards) {
            return [];
        }

        /** @var list<CardSiteReviewComment> $links */
        $links = $this->createQueryBuilder('l')
            ->addSelect('c')
            ->join('l.comment', 'c')
            ->where('l.card IN (:cards)')
            ->setParameter('cards', $cards)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byCard = [];
        foreach ($links as $link) {
            $byCard[(string) $link->card->id][] = $link;
        }

        return $byCard;
    }

    /**
     * How many unaddressed comments each card of a project carries, keyed by
     * card id. One aggregate for the whole board rather than a query per card,
     * and a card with none is absent rather than present as zero.
     *
     * @return array<string, int>
     */
    public function pendingCountsForProject(Project $project): array
    {
        /** @var list<array{cardId: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('card.id AS cardId', 'COUNT(l.id) AS total')
            ->join('l.card', 'card')
            ->join('l.comment', 'c')
            ->where('card.project = :project')
            ->andWhere('c.status = :pending')
            ->setParameter('project', $project)
            ->setParameter('pending', SiteReviewCommentStatus::Pending)
            ->groupBy('card.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['cardId']] = (int) $row['total'];
        }

        return $counts;
    }
}
