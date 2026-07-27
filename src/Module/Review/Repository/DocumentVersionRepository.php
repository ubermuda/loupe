<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<DocumentVersion> */
class DocumentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentVersion::class);
    }

    /**
     * The current version of a document — the one with the highest versionNumber
     * — without initializing the document's full `versions` collection. That
     * collection is unbounded and each row carries two TEXT columns holding the
     * full markdown and rendered HTML, so hydrating it just to read the last
     * element grows with the document's revision history forever.
     */
    public function findLatest(Document $document): DocumentVersion
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.document = :document')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() ?? throw new \LogicException('Document has no versions.');
    }

    /**
     * Latest-version metadata for a batch of documents in one query — a
     * projection that selects only the version id, number, and timestamp and
     * never the two TEXT columns. Meant for list views (the document
     * dashboard, the MCP document listing) that show many documents at once
     * and need only these fields per row, not the full markdown/HTML.
     *
     * @param list<Document> $documents
     *
     * @return array<string, array{versionId: Uuid, versionNumber: int, createdAt: \DateTimeImmutable}> keyed by document id
     */
    public function findLatestMetaByDocuments(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.document) AS documentId', 'v.id AS versionId', 'v.versionNumber AS versionNumber', 'v.createdAt AS createdAt')
            ->where('v.document IN (:documents)')
            ->andWhere('v.versionNumber = (SELECT MAX(v2.versionNumber) FROM App\Module\Review\Entity\DocumentVersion v2 WHERE v2.document = v.document)')
            ->setParameter('documents', $documents)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $versionId = $row['versionId'];
            $versionNumber = $row['versionNumber'];
            $createdAt = $row['createdAt'];

            $result[(string) $row['documentId']] = [
                'versionId' => $versionId instanceof Uuid ? $versionId : throw new \LogicException('versionId must be a Uuid.'),
                'versionNumber' => is_int($versionNumber) ? $versionNumber : throw new \LogicException('versionNumber must be an int.'),
                'createdAt' => $createdAt instanceof \DateTimeImmutable ? $createdAt : throw new \LogicException('createdAt must be a DateTimeImmutable.'),
            ];
        }

        return $result;
    }

    /**
     * A non-initializing proxy reference — used to pass a version identity to
     * another repository's query (e.g. a comment count) without an extra
     * SELECT for fields that query doesn't need.
     */
    public function getReferenceTo(Uuid $id): DocumentVersion
    {
        return $this->getEntityManager()->getReference(DocumentVersion::class, $id)
            ?? throw new \LogicException('getReference() never returns null for a valid class/id pair.');
    }
}
