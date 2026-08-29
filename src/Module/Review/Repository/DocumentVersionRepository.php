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
     * One numbered version of a document, or null when the document has no such
     * version. Scoped to the document so a version number from another document
     * cannot be reached by editing the URL.
     */
    public function findByNumber(Document $document, int $versionNumber): ?DocumentVersion
    {
        return $this->findOneBy(['document' => $document, 'versionNumber' => $versionNumber]);
    }

    /**
     * Every version of one document, newest first, as the metadata a version
     * switcher needs — never the markdown or rendered HTML. A document's
     * revision history is unbounded and each row carries both in full, so
     * hydrating entities here would make the switcher cost grow with the history
     * it is listing. The description is a third TEXT column but holds one line
     * per version, and the switcher renders it.
     *
     * @return list<array{versionNumber: int, createdAt: \DateTimeImmutable, description: ?string}>
     */
    public function findAllMetaByDocument(Document $document): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.versionNumber AS versionNumber', 'v.createdAt AS createdAt', 'v.description AS description')
            ->where('v.document = :document')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $meta = [];
        foreach ($rows as $row) {
            $versionNumber = $row['versionNumber'];
            $createdAt = $row['createdAt'];
            $description = $row['description'];

            $meta[] = [
                'versionNumber' => is_int($versionNumber) ? $versionNumber : throw new \LogicException('versionNumber must be an int.'),
                'createdAt' => $createdAt instanceof \DateTimeImmutable ? $createdAt : throw new \LogicException('createdAt must be a DateTimeImmutable.'),
                'description' => null === $description || is_string($description) ? $description : throw new \LogicException('description must be a string or null.'),
            ];
        }

        return $meta;
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

    /**
     * Every version's id and Markdown source.
     *
     * A cursor rather than a fetch, and that is part of the contract: this walks
     * the whole table and each row carries a document in full, so materialising
     * the result is a memory problem on a real installation.
     *
     * @return iterable<array{id: string, markdown_source: string}>
     */
    public function streamAllSources(): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT id, markdown_source FROM document_versions',
        );

        // Yielded one at a time rather than returned: the driver hands back
        // `mixed` per column, and shaping each row as it arrives keeps both the
        // declared type honest and the cursor lazy.
        foreach ($rows as $row) {
            yield [
                'id' => $this->text($row['id'], 'id'),
                'markdown_source' => $this->text($row['markdown_source'], 'markdown_source'),
            ];
        }
    }

    /**
     * Rewrites one version's rendered HTML, returning 1 when the row changed and
     * 0 when the new HTML already matched what was stored.
     *
     * Writes the column directly because DocumentVersion::$renderedHtml is
     * readonly — versions are immutable by design, and re-rendering stored HTML
     * from unchanged Markdown is the one sanctioned exception.
     */
    public function updateRenderedHtml(string $id, string $html): int
    {
        // executeStatement() is typed int|string because some drivers report the
        // affected-row count as a numeric string; an UPDATE's count is numeric.
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE document_versions SET rendered_html = :html WHERE id = :id::uuid AND rendered_html <> :html',
            ['html' => $html, 'id' => $id],
        );
    }

    /**
     * Every anchored comment paired with its version's Markdown source and
     * stored HTML, **ordered by version id**.
     *
     * The ordering is a correctness requirement rather than tidiness: callers
     * render each version once by watching for the id to change, so unordered
     * rows would re-render per comment instead of per version.
     *
     * Comments with an empty quote are excluded here rather than left to the
     * caller. An empty quote is the storage sentinel for a comment attached to
     * no span, and such a comment is never relocated — so including one could
     * only ever raise an alarm that cannot come true.
     *
     * A cursor rather than a fetch, for the same reason as streamAllSources().
     *
     * @return iterable<array{id: string, comment_id: string, markdown_source: string, rendered_html: string, anchor_quote: string, anchor_prefix: string, anchor_suffix: string, anchor_offset_hint: int}>
     */
    public function streamAnchoredCommentsByVersion(): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            "SELECT v.id, v.markdown_source, v.rendered_html, c.id AS comment_id,
                    c.anchor_quote, c.anchor_prefix, c.anchor_suffix, c.anchor_offset_hint
             FROM document_versions v
             JOIN comments c ON c.version_id = v.id AND c.anchor_quote <> ''
             ORDER BY v.id",
        );

        foreach ($rows as $row) {
            $offsetHint = $row['anchor_offset_hint'];

            yield [
                'id' => $this->text($row['id'], 'id'),
                'comment_id' => $this->text($row['comment_id'], 'comment_id'),
                'markdown_source' => $this->text($row['markdown_source'], 'markdown_source'),
                'rendered_html' => $this->text($row['rendered_html'], 'rendered_html'),
                'anchor_quote' => $this->text($row['anchor_quote'], 'anchor_quote'),
                'anchor_prefix' => $this->text($row['anchor_prefix'], 'anchor_prefix'),
                'anchor_suffix' => $this->text($row['anchor_suffix'], 'anchor_suffix'),
                // Integer columns arrive as a numeric string on some drivers.
                'anchor_offset_hint' => is_numeric($offsetHint)
                    ? (int) $offsetHint
                    : throw new \LogicException('anchor_offset_hint must be numeric.'),
            ];
        }
    }

    /** One text column, narrowed from the driver's `mixed` to the declared shape. */
    private function text(mixed $value, string $column): string
    {
        return \is_string($value)
            ? $value
            : throw new \LogicException(sprintf('Column %s must be a string.', $column));
    }

    /**
     * The number the next version of this document should carry.
     *
     * A MAX() rather than a count of the versions collection: the collection is
     * not EXTRA_LAZY, so counting it loads every version of the document. The
     * caller must hold the document's row lock, which is what makes read-then-
     * write safe against a concurrent revision.
     */
    public function nextVersionNumber(Document $document): int
    {
        return 1 + (int) $this->createQueryBuilder('v')
            ->select('COALESCE(MAX(v.versionNumber), 0)')
            ->andWhere('v.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
