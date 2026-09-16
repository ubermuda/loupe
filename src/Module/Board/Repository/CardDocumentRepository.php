<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\CardDocument;
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
}
