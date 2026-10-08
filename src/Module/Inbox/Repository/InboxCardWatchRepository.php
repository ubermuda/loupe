<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxCardWatch> */
class InboxCardWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxCardWatch::class);
    }

    /**
     * The open watches of the cards, with their items and waits.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<InboxCardWatch>
     */
    public function findOpenForCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        return array_values($this->forCards($project, $cardIds)
            ->addSelect('item')
            ->join('watch.item', 'item')
            ->andWhere('watch.closedAt IS NULL')
            ->getQuery()
            ->getResult());
    }

    /**
     * The dismissed watches of the cards, with their waits.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<InboxCardWatch>
     */
    public function findDismissedForCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        return array_values($this->forCards($project, $cardIds)
            ->andWhere('watch.dismissedAt IS NOT NULL')
            ->getQuery()
            ->getResult());
    }

    public function findOneForItem(InboxItem $item): ?InboxCardWatch
    {
        $watch = $this->createQueryBuilder('watch')
            ->addSelect('wait')
            ->leftJoin('watch.waits', 'wait')
            ->andWhere('watch.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getOneOrNullResult();

        return $watch instanceof InboxCardWatch ? $watch : null;
    }

    /**
     * The watches of the items, with their waits.
     *
     * @param list<InboxItem> $items
     *
     * @return list<InboxCardWatch>
     */
    public function findForItems(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        return array_values($this->createQueryBuilder('watch')
            ->addSelect('wait')
            ->leftJoin('watch.waits', 'wait')
            ->andWhere('watch.item IN (:items)')
            ->setParameter('items', $items)
            ->getQuery()
            ->getResult());
    }

    /**
     * The open watches whose card waits on a review of the document.
     *
     * @return list<InboxCardWatch>
     */
    public function findOpenForDocument(Document $document): array
    {
        return array_values($this->createQueryBuilder('watch')
            ->addSelect('item')
            ->join('watch.item', 'item')
            ->join('watch.waits', 'wait')
            ->andWhere('watch.project = :project')
            ->andWhere('watch.closedAt IS NULL')
            ->andWhere('wait.trigger = :trigger')
            ->andWhere('wait.documentId = :document')
            ->andWhere('wait.endedAt IS NULL')
            ->setParameter('project', $document->project)
            ->setParameter('trigger', InboxCardWaitTrigger::DocumentInReview)
            ->setParameter('document', $document->id, UuidType::NAME)
            ->orderBy('watch.cardNumber', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<string> */
    public function findOpenCardIds(Project $project): array
    {
        /** @var list<array{cardId: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('watch')
            ->select('watch.cardId AS cardId')
            ->andWhere('watch.project = :project')
            ->andWhere('watch.closedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['cardId'], $rows);
    }

    /** @return list<string> */
    public function findProjectIdsWithOpenWatch(): array
    {
        /** @var list<Uuid|string> $ids */
        $ids = $this->createQueryBuilder('watch')
            ->select('DISTINCT IDENTITY(watch.project)')
            ->andWhere('watch.closedAt IS NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (Uuid|string $id): string => (string) $id, $ids);
    }

    /** @param list<Uuid> $cardIds */
    private function forCards(Project $project, array $cardIds): QueryBuilder
    {
        return $this->createQueryBuilder('watch')
            ->addSelect('wait')
            ->leftJoin('watch.waits', 'wait')
            ->andWhere('watch.project = :project')
            ->andWhere('watch.cardId IN (:cards)')
            ->setParameter('project', $project)
            ->setParameter('cards', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds));
    }
}
