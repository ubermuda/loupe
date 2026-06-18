<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618205418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_versions (id UUID NOT NULL, version_number INT NOT NULL, markdown_source TEXT NOT NULL, rendered_html TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_961DB18BC33F7837 ON document_versions (document_id)');
        $this->addSql('CREATE TABLE documents (id UUID NOT NULL, status VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A2B072887E3C61F9 ON documents (owner_id)');
        $this->addSql('ALTER TABLE document_versions ADD CONSTRAINT FK_961DB18BC33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B072887E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_versions DROP CONSTRAINT FK_961DB18BC33F7837');
        $this->addSql('ALTER TABLE documents DROP CONSTRAINT FK_A2B072887E3C61F9');
        $this->addSql('DROP TABLE document_versions');
        $this->addSql('DROP TABLE documents');
    }
}
