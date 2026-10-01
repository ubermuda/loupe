<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardEvent> */
class CardEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardEvent::class);
    }

    /**
     * Persists and does not flush: every caller writes inside a transaction
     * that flushes the row together with the change it records.
     *
     * @param array<string, mixed> $detail
     */
    public function record(Card $card, CardEventKind $kind, CardReporter $actorKind, ?User $actorUser, array $detail, ?\DateTimeImmutable $at = null): CardEvent
    {
        $event = new CardEvent($card, $card->project, $kind, $actorKind, $actorUser, $detail, $at ?? new \DateTimeImmutable());
        $this->getEntityManager()->persist($event);

        return $event;
    }

    /** @return list<CardEvent> newest first */
    public function findForCard(Card $card): array
    {
        return array_values($this->findBy(['card' => $card], ['occurredAt' => 'DESC', 'id' => 'DESC']));
    }

    /** @return list<CardEvent> newest first, each with its actor loaded */
    public function findPageForCard(Card $card, int $offset, int $limit): array
    {
        return array_values($this->createQueryBuilder('e')
            ->leftJoin('e.actorUser', 'u')
            ->addSelect('u')
            ->andWhere('e.card = :card')
            ->setParameter('card', $card)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    public function countForCard(Card $card): int
    {
        return $this->count(['card' => $card]);
    }
}
