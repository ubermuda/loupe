<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702023711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create site_review_sites (per-site site-review model, phase 1).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_review_sites (id UUID NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, token_id UUID DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64041BCB41DEE7B9 ON site_review_sites (token_id)');
        $this->addSql('CREATE INDEX IDX_64041BCB7E3C61F9 ON site_review_sites (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_review_site_owner_name ON site_review_sites (owner_id, name)');
        $this->addSql('ALTER TABLE site_review_sites ADD CONSTRAINT FK_64041BCB41DEE7B9 FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE site_review_sites ADD CONSTRAINT FK_64041BCB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site_review_sites DROP CONSTRAINT FK_64041BCB41DEE7B9');
        $this->addSql('ALTER TABLE site_review_sites DROP CONSTRAINT FK_64041BCB7E3C61F9');
        $this->addSql('DROP TABLE site_review_sites');
    }
}
