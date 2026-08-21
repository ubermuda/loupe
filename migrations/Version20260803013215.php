<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803013215 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create project-scoped tags and the document_tags join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_tags (document_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (document_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_C80818B5C33F7837 ON document_tags (document_id)');
        $this->addSql('CREATE INDEX IDX_C80818B5BAD26311 ON document_tags (tag_id)');
        $this->addSql('CREATE TABLE tags (id UUID NOT NULL, name VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6FBC9426166D1F9C ON tags (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tag_project_name ON tags (project_id, name)');
        $this->addSql('ALTER TABLE document_tags ADD CONSTRAINT FK_C80818B5C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_tags ADD CONSTRAINT FK_C80818B5BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_6FBC9426166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_tags DROP CONSTRAINT FK_C80818B5C33F7837');
        $this->addSql('ALTER TABLE document_tags DROP CONSTRAINT FK_C80818B5BAD26311');
        $this->addSql('ALTER TABLE tags DROP CONSTRAINT FK_6FBC9426166D1F9C');
        $this->addSql('DROP TABLE document_tags');
        $this->addSql('DROP TABLE tags');
    }
}
