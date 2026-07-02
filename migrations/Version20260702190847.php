<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702190847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename Site to Project: new projects table with domain and per-binding token columns; site reviews now belong to a project.';
    }

    public function up(Schema $schema): void
    {
        // Destructive, pre-prod only (approved): existing review rows reference
        // the dropped site_review_sites table, so they cannot be carried over.
        $this->addSql('DELETE FROM site_review_comments');
        $this->addSql('DELETE FROM site_review_reviews');

        $this->addSql('CREATE TABLE projects (id UUID NOT NULL, name VARCHAR(100) NOT NULL, domain VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, widget_token_id UUID DEFAULT NULL, mcp_token_id UUID DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C93B3A4ADB33A98 ON projects (widget_token_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C93B3A47B40685F ON projects (mcp_token_id)');
        $this->addSql('CREATE INDEX IDX_5C93B3A47E3C61F9 ON projects (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_owner_name ON projects (owner_id, name)');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A4ADB33A98 FOREIGN KEY (widget_token_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A47B40685F FOREIGN KEY (mcp_token_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE site_review_reviews DROP CONSTRAINT fk_b3c886f0f6bd1646');
        $this->addSql('ALTER TABLE site_review_sites DROP CONSTRAINT fk_64041bcb41dee7b9');
        $this->addSql('ALTER TABLE site_review_sites DROP CONSTRAINT fk_64041bcb7e3c61f9');
        $this->addSql('DROP TABLE site_review_sites');
        $this->addSql('DROP INDEX uniq_site_review_in_progress');
        $this->addSql('DROP INDEX idx_b3c886f0f6bd1646');
        $this->addSql('ALTER TABLE site_review_reviews RENAME COLUMN site_id TO project_id');
        $this->addSql('ALTER TABLE site_review_reviews ADD CONSTRAINT FK_B3C886F0166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B3C886F0166D1F9C ON site_review_reviews (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_review_in_progress ON site_review_reviews (project_id) WHERE ((status)::text = \'in-progress\'::text)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_review_sites (id UUID NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, token_id UUID DEFAULT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_64041bcb41dee7b9 ON site_review_sites (token_id)');
        $this->addSql('CREATE INDEX idx_64041bcb7e3c61f9 ON site_review_sites (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_review_site_owner_name ON site_review_sites (owner_id, name)');
        $this->addSql('ALTER TABLE site_review_sites ADD CONSTRAINT fk_64041bcb41dee7b9 FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE site_review_sites ADD CONSTRAINT fk_64041bcb7e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT FK_5C93B3A4ADB33A98');
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT FK_5C93B3A47B40685F');
        $this->addSql('ALTER TABLE projects DROP CONSTRAINT FK_5C93B3A47E3C61F9');
        $this->addSql('DROP TABLE projects');
        $this->addSql('ALTER TABLE site_review_reviews DROP CONSTRAINT FK_B3C886F0166D1F9C');
        $this->addSql('DROP INDEX IDX_B3C886F0166D1F9C');
        $this->addSql('DROP INDEX uniq_site_review_in_progress');
        $this->addSql('ALTER TABLE site_review_reviews RENAME COLUMN project_id TO site_id');
        $this->addSql('ALTER TABLE site_review_reviews ADD CONSTRAINT fk_b3c886f0f6bd1646 FOREIGN KEY (site_id) REFERENCES site_review_sites (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_b3c886f0f6bd1646 ON site_review_reviews (site_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_review_in_progress ON site_review_reviews (site_id) WHERE ((status)::text = \'in-progress\'::text)');
    }
}
