<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InboxAsk>
 */
class InboxAskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxAsk::class);
    }

    /** The session's open ask in any project. At most one exists. */
    public function findOpenForSession(Uuid $sessionId): ?InboxAsk
    {
        /* @var ?InboxAsk */
        return $this->createQueryBuilder('a')
            ->andWhere('a.sessionId = :sessionId')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every ask that holds the item, oldest first.
     *
     * @return list<InboxAsk>
     */
    public function findHolding(InboxItem $item): array
    {
        /* @var list<InboxAsk> */
        return $this->createQueryBuilder('a')
            ->join('a.items', 'l')
            ->andWhere('l.item = :item')
            ->setParameter('item', $item)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every ask in the projects the user owns, with its item memberships.
     *
     * @return list<InboxAsk>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.project', 'p')
            ->leftJoin('a.items', 'l')
            ->addSelect('l')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
