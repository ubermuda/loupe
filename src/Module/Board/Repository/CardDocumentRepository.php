<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<CardDocument> */
final class CardDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardDocument::class);
    }

    /** @return array<string, int> card id => linked documents; a card with none has no key */
    public function countsForProject(Project $project): array
    {
        /** @var list<array{cardId: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('card.id AS cardId', 'COUNT(link.id) AS total')
            ->join('link.card', 'card')
            ->where('card.project = :project')
            ->setParameter('project', $project)
            ->groupBy('card.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['cardId']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countForCard(Card $card): int
    {
        return (int) $this->createQueryBuilder('link')
            ->select('COUNT(link.id)')
            ->where('link.card = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CardDocument> */
    public function findForDocument(Document $document): array
    {
        return $this->createQueryBuilder('link')
            ->addSelect('card', 'column')
            ->join('link.card', 'card')
            ->join('card.column', 'column')
            ->where('link.document = :document')
            ->setParameter('document', $document)
            ->orderBy('link.linkedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<CardDocument>
     */
    public function findForDocuments(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        return $this->createQueryBuilder('link')
            ->addSelect('card', 'column')
            ->join('link.card', 'card')
            ->join('card.column', 'column')
            ->where('link.document IN (:documents)')
            ->setParameter('documents', $documents)
            ->orderBy('link.linkedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> the ids of the cards linked to the document */
    public function findCardIdsForDocument(Uuid $documentId): array
    {
        /** @var list<array{cardId: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('link')
            ->select('DISTINCT IDENTITY(link.card) AS cardId')
            ->where('link.document = :document')
            ->setParameter('document', $documentId, UuidType::NAME)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['cardId'], $rows);
    }

    /**
     * The links of the cards to a document in review and not archived, with the
     * number of its current version. The card column and the document tags come
     * loaded, so a caller can apply the stage rule with no query per row.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<array{link: CardDocument, versionNumber: int}>
     */
    public function findInReviewForCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{0: CardDocument, versionNumber: int|string}> $rows */
        $rows = $this->inReview($project)
            ->join('card.column', 'cardColumn')
            ->leftJoin('document.tags', 'tag')
            ->addSelect('card', 'cardColumn', 'document', 'tag')
            ->addSelect(\sprintf('(SELECT MAX(version.versionNumber) FROM %s version WHERE version.document = document) AS versionNumber', DocumentVersion::class))
            ->andWhere('card.id IN (:cards)')
            ->setParameter('cards', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->orderBy('link.linkedAt', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['link' => $row[0], 'versionNumber' => (int) $row['versionNumber']], $rows);
    }

    /** @return list<string> the ids of the cards linked to a document in review and not archived */
    public function findCardIdsWithDocumentInReview(Project $project): array
    {
        /** @var list<array{cardId: Uuid|string}> $rows */
        $rows = $this->inReview($project)
            ->select('DISTINCT card.id AS cardId')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['cardId'], $rows);
    }

    /**
     * The status and the tag names of each unarchived document linked to the card, as the
     * database holds them now, in link order.
     *
     * @return list<array{status: string, tags: list<string>}>
     */
    public function findStatusesAndTagsForCard(Card $card): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT d.id AS document_id, d.status, t.name AS tag_name FROM board_card_documents cd
             JOIN documents d ON d.id = cd.document_id
             LEFT JOIN document_tags dt ON dt.document_id = d.id
             LEFT JOIN tags t ON t.id = dt.tag_id
             WHERE cd.card_id = :card AND d.archived_at IS NULL
             ORDER BY cd.linked_at, d.id, t.name',
            ['card' => (string) $card->id],
        );

        $documents = [];
        foreach ($rows as $row) {
            $documentId = (string) $row['document_id'];
            $documents[$documentId] ??= ['status' => (string) $row['status'], 'tags' => []];
            if (null !== $row['tag_name']) {
                $documents[$documentId]['tags'][] = (string) $row['tag_name'];
            }
        }

        return array_values($documents);
    }

    private function inReview(Project $project): QueryBuilder
    {
        return $this->createQueryBuilder('link')
            ->join('link.card', 'card')
            ->join('link.document', 'document')
            ->andWhere('card.project = :project')
            ->andWhere('document.status = :status')
            ->andWhere('document.archivedAt IS NULL')
            ->setParameter('project', $project)
            ->setParameter('status', DocumentStatus::InReview);
    }
}
