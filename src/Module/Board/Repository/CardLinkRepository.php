<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardLink> */
final class CardLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardLink::class);
    }

    /**
     * Every link that names the card on either side, oldest first.
     *
     * @return list<CardLink>
     */
    public function findForCard(Card $card): array
    {
        /* @var list<CardLink> */
        return $this->linksQuery()
            ->where('link.source = :card OR link.target = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getResult();
    }

    /**
     * The links of many cards in one query, keyed by card id, oldest first.
     * A row between two of the given cards appears under both of them.
     *
     * @param list<Card> $cards
     *
     * @return array<string, list<CardLink>>
     */
    public function findForCards(array $cards): array
    {
        if ([] === $cards) {
            return [];
        }

        /** @var list<CardLink> $links */
        $links = $this->linksQuery()
            ->where('link.source IN (:cards) OR link.target IN (:cards)')
            ->setParameter('cards', $cards)
            ->getQuery()
            ->getResult();

        $wanted = [];
        foreach ($cards as $card) {
            $wanted[(string) $card->id] = true;
        }

        $byCard = [];
        foreach ($links as $link) {
            foreach ([(string) $link->source->id, (string) $link->target->id] as $id) {
                if (isset($wanted[$id])) {
                    $byCard[$id][] = $link;
                }
            }
        }

        return $byCard;
    }

    private function linksQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('link')
            ->addSelect('source', 'sourceColumn', 'target', 'targetColumn')
            ->join('link.source', 'source')
            ->join('source.column', 'sourceColumn')
            ->join('link.target', 'target')
            ->join('target.column', 'targetColumn')
            ->orderBy('link.linkedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC');
    }
}
