<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702200818 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Documents belong to a project; wipe pre-project document data (pre-prod).';
    }

    public function up(Schema $schema): void
    {
        // Pre-prod destructive wipe (approved): existing documents predate the
        // project relation and cannot be backfilled.
        $this->addSql('DELETE FROM comments');
        $this->addSql('DELETE FROM reviews');
        $this->addSql('DELETE FROM document_versions');
        $this->addSql('DELETE FROM documents');
        $this->addSql('ALTER TABLE documents ADD project_id UUID NOT NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B07288166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A2B07288166D1F9C ON documents (project_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_A2B07288166D1F9C');
        $this->addSql('DROP INDEX IDX_A2B07288166D1F9C');
        $this->addSql('ALTER TABLE documents DROP project_id');
    }
}
