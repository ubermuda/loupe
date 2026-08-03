<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Doctrine\FullTextSearch;
use App\Module\Review\Entity\Document;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds a document's search vector from its title and its current version's
 * markdown.
 *
 * Every write that changes either input calls this — creating a document,
 * revising one, renaming one. A database trigger would do the same work
 * invisibly; this codebase has none, and keeping it in the handlers is what makes
 * it greppable.
 *
 * The three callers do not wrap this the same way, and that is deliberate.
 * ReviseDocumentHandler already holds a transaction for the version-number lock,
 * so the index lands inside it. Create and rename each end in a single flush, and
 * wrapping those would close the EntityManager on a rejected tag name — changing
 * error handling that has nothing to do with search. What all three share is the
 * ordering: the rows must be flushed before this runs.
 */
final readonly class DocumentSearchIndexer
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The current version is read back over SQL rather than through the entity:
     * `$document->currentVersion()` initialises the whole `versions` collection,
     * and every row of it carries the full markdown and rendered HTML — so a
     * rename would load the entire revision history to change a title.
     */
    public function index(Document $document): void
    {
        // DISTINCT ON picks the highest version number, matching the backfill in
        // the migration that introduced the column. The two are written out
        // separately on purpose: a migration is a frozen record of what already
        // ran, so it must not change meaning when this expression is next edited.
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    UPDATE documents d
                    SET search_vector = setweight(to_tsvector('%1$s', d.title), '%2$s')
                        || setweight(to_tsvector('%1$s', v.markdown_source), '%3$s')
                    FROM (
                        SELECT DISTINCT ON (document_id) document_id, markdown_source
                        FROM document_versions
                        WHERE document_id = :id
                        ORDER BY document_id, version_number DESC
                    ) v
                    WHERE d.id = :id AND v.document_id = d.id
                    SQL,
                FullTextSearch::CONFIGURATION,
                FullTextSearch::TITLE_WEIGHT,
                FullTextSearch::BODY_WEIGHT,
            ),
            ['id' => (string) $document->id],
        );
    }
}
