<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardPause> */
class CardPauseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardPause::class);
    }

    /** Read fresh, so a caller that holds the card lock sees a release another transaction committed. */
    public function findActiveForCard(Card $card): ?CardPause
    {
        return $this->createQueryBuilder('pause')
            ->andWhere('pause.card = :card')
            ->andWhere('pause.releasedAt IS NULL')
            ->setParameter('card', $card)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * @param list<string|Uuid> $cardIds
     *
     * @return array<string, CardPause> keyed by the RFC 4122 card id; a card with no active pause has no entry
     */
    public function findActiveForCardIds(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<CardPause> $pauses */
        $pauses = $this->createQueryBuilder('pause')
            ->andWhere('pause.card IN (:cards)')
            ->andWhere('pause.releasedAt IS NULL')
            ->setParameter('cards', array_map(static fn (string|Uuid $id): string => $id instanceof Uuid ? $id->toRfc4122() : Uuid::fromString($id)->toRfc4122(), $cardIds))
            ->getQuery()
            ->getResult();

        $byCard = [];
        foreach ($pauses as $pause) {
            $byCard[(string) $pause->card->id] = $pause;
        }

        return $byCard;
    }
}
