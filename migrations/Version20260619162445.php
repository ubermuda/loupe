<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619162445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create site_review_batches and site_review_comments tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_review_batches (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2AD608AC7E3C61F9 ON site_review_batches (owner_id)');
        $this->addSql('CREATE TABLE site_review_comments (id UUID NOT NULL, position INT NOT NULL, body TEXT NOT NULL, selector TEXT NOT NULL, text TEXT NOT NULL, url TEXT NOT NULL, batch_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7246C1CAF39EBE7A ON site_review_comments (batch_id)');
        $this->addSql('ALTER TABLE site_review_batches ADD CONSTRAINT FK_2AD608AC7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE site_review_comments ADD CONSTRAINT FK_7246C1CAF39EBE7A FOREIGN KEY (batch_id) REFERENCES site_review_batches (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site_review_batches DROP CONSTRAINT FK_2AD608AC7E3C61F9');
        $this->addSql('ALTER TABLE site_review_comments DROP CONSTRAINT FK_7246C1CAF39EBE7A');
        $this->addSql('DROP TABLE site_review_batches');
        $this->addSql('DROP TABLE site_review_comments');
    }
}
