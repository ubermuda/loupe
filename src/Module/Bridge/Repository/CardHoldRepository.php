<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CardHold>
 */
class CardHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardHold::class);
    }

    public function findOneOfCard(Project $project, Uuid $cardId): ?CardHold
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.project = :project')
            ->andWhere('h.cardId = :cardId')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForCard(Project $project, Uuid $cardId): bool
    {
        return null !== $this->createQueryBuilder('h')
            ->select('1')
            ->andWhere('h.project = :project')
            ->andWhere('h.cardId = :cardId')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @param non-empty-list<Uuid> $cardIds */
    public function deleteOfCards(Project $project, array $cardIds): int
    {
        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->andWhere('h.project = :project')
            ->andWhere('h.cardId IN (:cardIds)')
            ->setParameter('project', $project)
            ->setParameter('cardIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->getQuery()
            ->execute();
    }

    /** @return list<CardHold> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('hold')
            ->join('hold.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('hold.heldAt', 'ASC')
            ->addOrderBy('hold.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
