<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Each of the project's cards with these ids, with its rows of the kinds.
     * A card with no such row comes back once with a null kind.
     *
     * @param list<Uuid>          $cardIds
     * @param list<CardEventKind> $kinds
     *
     * @return list<array{cardId: string, kind: ?CardEventKind, detail: array<mixed>}>
     */
    public function findKindsOfCards(Project $project, array $cardIds, array $kinds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{card_id: string, kind: ?string, detail: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.id AS card_id, e.kind, e.detail
            FROM board_cards c
            LEFT JOIN board_card_events e ON e.card_id = c.id AND e.kind IN (:kinds)
            WHERE c.project_id = :project AND c.id IN (:cards)',
            [
                'project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122(),
                'cards' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
                'kinds' => array_map(static fn (CardEventKind $kind): string => $kind->value, $kinds),
            ],
            ['cards' => ArrayParameterType::STRING, 'kinds' => ArrayParameterType::STRING],
        );

        return array_map(static function (array $row): array {
            $detail = null === $row['detail'] ? [] : json_decode($row['detail'], true, flags: \JSON_THROW_ON_ERROR);

            return [
                'cardId' => $row['card_id'],
                'kind' => null === $row['kind'] ? null : CardEventKind::from($row['kind']),
                'detail' => \is_array($detail) ? $detail : [],
            ];
        }, $rows);
    }

    public function findFirstOccurredAt(Project $project): ?\DateTimeImmutable
    {
        $connection = $this->getEntityManager()->getConnection();
        $first = $connection->fetchOne(
            'SELECT MIN(occurred_at) FROM board_card_events WHERE project_id = :project',
            ['project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122()],
        );

        $value = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($first, $connection->getDatabasePlatform());

        return $value instanceof \DateTimeImmutable ? $value : null;
    }
}
