<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904161839 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add a per-document and per-project full-text search language, defaulting to English';
    }

    public function up(Schema $schema): void
    {
        // No reindex: every existing row was stemmed as English, which is the
        // value the DEFAULT gives it.
        $this->addSql('ALTER TABLE documents ADD search_language VARCHAR(20) DEFAULT \'english\' NOT NULL');
        $this->addSql('CREATE INDEX idx_documents_project_search_language ON documents (project_id, search_language)');
        $this->addSql('ALTER TABLE projects ADD search_language VARCHAR(20) DEFAULT \'english\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_documents_project_search_language');
        $this->addSql('ALTER TABLE documents DROP search_language');
        $this->addSql('ALTER TABLE projects DROP search_language');
    }
}
