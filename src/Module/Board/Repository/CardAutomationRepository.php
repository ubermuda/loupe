<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardAutomation> */
class CardAutomationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardAutomation::class);
    }

    /**
     * Answers the row of the card, locked until the caller's transaction ends.
     * The insert skips a concurrent insert of the same card, so it neither fails
     * nor aborts the transaction. The refresh hint re-reads a row this entity
     * manager already holds, so the lock never guards stale fields.
     */
    public function findOrCreateForUpdate(Card $card): CardAutomation
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO board_card_automations (id, card_id, fix_rounds) VALUES (:id, :card, 0) ON CONFLICT (card_id) DO NOTHING',
            ['id' => Uuid::v7()->toRfc4122(), 'card' => (string) $card->id],
        );

        $automation = $this->createQueryBuilder('automation')
            ->andWhere('automation.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $automation instanceof CardAutomation ? $automation : throw new \LogicException('The row exists after the insert.');
    }

    /** Clears the queued Backlog move of the card when it is this one. A card with no row, or a deleted card, changes nothing. */
    public function consumeAbandonedMoveToken(Card $card, Uuid $token): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE board_card_automations SET abandoned_move_token = NULL WHERE card_id = :card AND abandoned_move_token = :token',
            ['card' => (string) $card->id, 'token' => $token->toRfc4122()],
        );
    }

    /**
     * @param list<Uuid> $cardIds
     *
     * @return array<string, CardAutomation> keyed by card id; a card with no row has no entry
     */
    public function findByCardIds(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<CardAutomation> $rows */
        $rows = $this->createQueryBuilder('automation')
            ->andWhere('automation.card IN (:cards)')
            ->setParameter('cards', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->getQuery()
            ->getResult();

        $byCard = [];
        foreach ($rows as $row) {
            $byCard[(string) $row->card->id] = $row;
        }

        return $byCard;
    }

    /** Clears the fix rounds and the block of the card. A card with no row keeps none. */
    public function reset(Card $card): void
    {
        $this->createQueryBuilder('automation')
            ->update()
            ->set('automation.fixRounds', 0)
            ->set('automation.blockedReason', 'NULL')
            ->andWhere('automation.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->execute();
    }
}
