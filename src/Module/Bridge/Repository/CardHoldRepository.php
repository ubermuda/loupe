<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * One statement, so it joins a caller's transaction.
     *
     * @param non-empty-list<Uuid> $cardIds
     *
     * @return list<Uuid> the cards whose hold went
     */
    public function deleteOfCards(Project $project, array $cardIds): array
    {
        return array_map(Uuid::fromString(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'DELETE FROM bridge_card_holds WHERE project_id = :project AND card_id IN (:cardIds) RETURNING card_id',
            [
                'project' => ($project->id ?? throw new \LogicException('A persisted project has an id.'))->toRfc4122(),
                'cardIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
            ],
            ['cardIds' => ArrayParameterType::STRING],
        ));
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
