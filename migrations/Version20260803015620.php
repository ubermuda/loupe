<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803015620 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add a weighted full-text search vector to documents, backfilled from each document\'s current version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD search_vector TSVECTOR DEFAULT NULL');

        // Existing documents get their vector here rather than waiting for their
        // next revision — an unindexed row matches nothing, so search would come
        // up empty on every document that predates this.
        //
        // DISTINCT ON picks each document's highest version number: indexing every
        // historical version would return a document for text it no longer
        // contains.
        $this->addSql(<<<'SQL'
            UPDATE documents d
            SET search_vector = setweight(to_tsvector('english', d.title), 'A')
                || setweight(to_tsvector('english', v.markdown_source), 'B')
            FROM (
                SELECT DISTINCT ON (document_id) document_id, markdown_source
                FROM document_versions
                ORDER BY document_id, version_number DESC
            ) v
            WHERE v.document_id = d.id
            SQL);

        // Written by hand because DBAL's Postgres platform emits no USING clause:
        // a B-tree index on a tsvector is built happily and never used by @@.
        $this->addSql('CREATE INDEX idx_documents_search_vector ON documents USING gin (search_vector)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_documents_search_vector');
        $this->addSql('ALTER TABLE documents DROP search_vector');
    }
}
