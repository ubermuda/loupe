<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
}
