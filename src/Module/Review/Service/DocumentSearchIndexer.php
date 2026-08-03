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
 * invisibly; this codebase has none and keeping the behaviour in the handlers is
 * what makes it greppable.
 */
final readonly class DocumentSearchIndexer
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Must run after the document (and any new version) has been flushed: it is
     * a SQL UPDATE against a row the ORM has to have written first, and inside
     * the caller's transaction when there is one, so a rolled-back revision does
     * not leave the vector describing a version that never existed.
     */
    public function index(Document $document): void
    {
        $this->connection->executeStatement(
            \sprintf(
                'UPDATE documents SET search_vector ='
                ." setweight(to_tsvector('%s', :title), '%s')"
                ." || setweight(to_tsvector('%s', :body), '%s')"
                .' WHERE id = :id',
                FullTextSearch::CONFIGURATION,
                FullTextSearch::TITLE_WEIGHT,
                FullTextSearch::CONFIGURATION,
                FullTextSearch::BODY_WEIGHT,
            ),
            [
                'title' => $document->title,
                'body' => $document->currentVersion()->markdownSource,
                'id' => (string) $document->id,
            ],
        );
    }
}
