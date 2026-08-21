<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702032346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Per-site site reviews: drop batch tables, add reviews and reshaped comments.';
    }

    public function up(Schema $schema): void
    {
        // Create the new per-site reviews table
        $this->addSql('CREATE TABLE site_review_reviews (id UUID NOT NULL, status VARCHAR(20) NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, site_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_B3C886F0F6BD1646 ON site_review_reviews (site_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_site_review_in_progress ON site_review_reviews (site_id) WHERE (status = \'in-progress\')');
        $this->addSql('ALTER TABLE site_review_reviews ADD CONSTRAINT FK_B3C886F0F6BD1646 FOREIGN KEY (site_id) REFERENCES site_review_sites (id) NOT DEFERRABLE');

        // Drop old comments first (they FK-reference batches; dropping them unblocks the batches drop)
        $this->addSql('DROP TABLE site_review_comments');

        // Drop old batches table
        $this->addSql('DROP TABLE site_review_batches');

        // Create reshaped comments table (FK now points at site_review_reviews)
        $this->addSql('CREATE TABLE site_review_comments (id UUID NOT NULL, status VARCHAR(20) NOT NULL, position INT NOT NULL, body TEXT NOT NULL, selector TEXT NOT NULL, text TEXT NOT NULL, url TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, review_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7246C1CA3E2E969B ON site_review_comments (review_id)');
        $this->addSql('ALTER TABLE site_review_comments ADD CONSTRAINT FK_7246C1CA3E2E969B FOREIGN KEY (review_id) REFERENCES site_review_reviews (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_review_batches (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_2ad608ac7e3c61f9 ON site_review_batches (owner_id)');
        $this->addSql('ALTER TABLE site_review_batches ADD CONSTRAINT fk_2ad608ac7e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE site_review_comments');
        $this->addSql('ALTER TABLE site_review_reviews DROP CONSTRAINT FK_B3C886F0F6BD1646');
        $this->addSql('DROP TABLE site_review_reviews');
        $this->addSql('CREATE TABLE site_review_comments (id UUID NOT NULL, position INT NOT NULL, body TEXT NOT NULL, selector TEXT NOT NULL, text TEXT NOT NULL, url TEXT NOT NULL, batch_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_7246c1caf39ebe7a ON site_review_comments (batch_id)');
        $this->addSql('ALTER TABLE site_review_comments ADD CONSTRAINT fk_7246c1caf39ebe7a FOREIGN KEY (batch_id) REFERENCES site_review_batches (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
