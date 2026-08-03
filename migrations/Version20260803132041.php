<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803132041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create document_highlights table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_highlights (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, anchor_quote TEXT NOT NULL, anchor_prefix VARCHAR(255) NOT NULL, anchor_suffix VARCHAR(255) NOT NULL, anchor_offset_hint INT NOT NULL, version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9BB008304BBC2705 ON document_highlights (version_id)');
        $this->addSql('ALTER TABLE document_highlights ADD CONSTRAINT FK_9BB008304BBC2705 FOREIGN KEY (version_id) REFERENCES document_versions (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_highlights DROP CONSTRAINT FK_9BB008304BBC2705');
        $this->addSql('DROP TABLE document_highlights');
    }
}
