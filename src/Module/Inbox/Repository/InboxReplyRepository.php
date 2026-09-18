<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxReply;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/** @extends ServiceEntityRepository<InboxReply> */
class InboxReplyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxReply::class);
    }

    /** @return list<InboxReply> */
    public function findForItem(InboxItem $item): array
    {
        return $this->findForItems([$item]);
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return list<InboxReply>
     */
    public function findForItems(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        return $this->createQueryBuilder('reply')
            ->addSelect('author')
            ->join('reply.author', 'author')
            ->andWhere('reply.item IN (:items)')
            ->setParameter('items', $items)
            ->orderBy('reply.createdAt', 'ASC')
            ->addOrderBy('reply.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return iterable<InboxReply> */
    public function findByOwner(User $owner): iterable
    {
        return $this->createQueryBuilder('reply')
            ->join('reply.item', 'item')
            ->join('item.project', 'project')
            ->andWhere('project.owner = :owner')
            ->setParameter('owner', $owner->id, UuidType::NAME)
            ->orderBy('reply.createdAt', 'ASC')
            ->addOrderBy('reply.id', 'ASC')
            ->getQuery()->toIterable();
    }
}
