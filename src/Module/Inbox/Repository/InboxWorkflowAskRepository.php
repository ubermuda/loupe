<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxWorkflowAsk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxWorkflowAsk> */
class InboxWorkflowAskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxWorkflowAsk::class);
    }

    /**
     * The open items that a card's rules asked, by card id, so a deleted card still finds them.
     *
     * @return list<InboxItem>
     */
    public function findOpenItemsForCard(Uuid $cardId): array
    {
        /** @var list<InboxWorkflowAsk> $asks */
        $asks = $this->createQueryBuilder('ask')
            ->addSelect('item')
            ->join('ask.item', 'item')
            ->andWhere('ask.cardId = :card')
            ->andWhere('item.state = :open')
            ->setParameter('card', $cardId->toRfc4122())
            ->setParameter('open', InboxItemState::Open)
            ->getQuery()
            ->getResult();

        return array_map(static fn (InboxWorkflowAsk $ask): InboxItem => $ask->item, $asks);
    }
}
