<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904160920 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create project-scoped series and place documents in them by ordinal';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE series (id UUID NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3A10012D166D1F9C ON series (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_series_project_name ON series (project_id, name)');
        $this->addSql('ALTER TABLE series ADD CONSTRAINT FK_3A10012D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documents ADD series_ordinal INT DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD series_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B072885278319C FOREIGN KEY (series_id) REFERENCES series (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A2B072885278319C ON documents (series_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_series_ordinal ON documents (series_id, series_ordinal)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The generated order dropped `series` while documents still referenced
        // it, which Postgres refuses. The referencing side goes first.
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_A2B072885278319C');
        $this->addSql('DROP INDEX IDX_A2B072885278319C');
        $this->addSql('DROP INDEX uniq_document_series_ordinal');
        $this->addSql('ALTER TABLE documents DROP series_ordinal');
        $this->addSql('ALTER TABLE documents DROP series_id');
        $this->addSql('ALTER TABLE series DROP CONSTRAINT FK_3A10012D166D1F9C');
        $this->addSql('DROP TABLE series');
    }
}
