<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<InboxReview> */
class InboxReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxReview::class);
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return list<InboxReview>
     */
    public function findForItems(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        return $this->createQueryBuilder('review')
            ->addSelect('reviewer')
            ->leftJoin('review.reviewer', 'reviewer')
            ->andWhere('review.item IN (:items)')
            ->setParameter('items', $items)
            ->getQuery()
            ->getResult();
    }

    /** @return list<InboxReview> */
    public function findOpenForDocument(Document $document): array
    {
        return $this->createQueryBuilder('review')
            ->addSelect('item')
            ->join('review.item', 'item')
            ->andWhere('review.document = :document')
            ->andWhere('item.project = :project')
            ->andWhere('item.state = :state')
            ->setParameter('document', $document)
            ->setParameter('project', $document->project)
            ->setParameter('state', InboxItemState::Open)
            ->getQuery()
            ->getResult();
    }

    /**
     * The documents among these that an open review item of an agent ask targets.
     *
     * @param list<Document> $documents
     *
     * @return list<string> document ids
     */
    public function findDocumentIdsUnderAgentReview(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        /** @var list<array{documentId: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('review')
            ->select('DISTINCT IDENTITY(review.document) AS documentId')
            ->join('review.item', 'item')
            ->join(InboxAskItem::class, 'link', Join::ON, 'link.item = item')
            ->join('link.ask', 'ask')
            ->andWhere('review.document IN (:documents)')
            ->andWhere('item.state = :state')
            ->andWhere('ask.origin = :origin')
            ->setParameter('documents', $documents)
            ->setParameter('state', InboxItemState::Open)
            ->setParameter('origin', InboxAskOrigin::Agent)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['documentId'], $rows);
    }

    /** @return iterable<InboxReview> */
    public function findByOwner(User $owner): iterable
    {
        return $this->createQueryBuilder('review')
            ->addSelect('item', 'project')
            ->join('review.item', 'item')
            ->join('item.project', 'project')
            ->andWhere('project.owner = :owner')
            ->setParameter('owner', $owner->id, UuidType::NAME)
            ->orderBy('item.createdAt', 'ASC')
            ->addOrderBy('item.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }
}
