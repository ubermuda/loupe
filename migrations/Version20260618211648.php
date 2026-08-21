<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618211648 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create comments and reviews tables with embedded anchor columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comments (id UUID NOT NULL, resolved BOOLEAN NOT NULL, orphaned BOOLEAN NOT NULL, body TEXT NOT NULL, anchor_quote TEXT NOT NULL, anchor_prefix VARCHAR(255) NOT NULL, anchor_suffix VARCHAR(255) NOT NULL, anchor_offset_hint INT NOT NULL, version_id UUID NOT NULL, author_id UUID NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5F9E962A4BBC2705 ON comments (version_id)');
        $this->addSql('CREATE INDEX IDX_5F9E962AF675F31B ON comments (author_id)');
        $this->addSql('CREATE INDEX IDX_5F9E962A727ACA70 ON comments (parent_id)');
        $this->addSql('CREATE TABLE reviews (id UUID NOT NULL, verdict VARCHAR(255) NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version_id UUID NOT NULL, reviewer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6970EB0F4BBC2705 ON reviews (version_id)');
        $this->addSql('CREATE INDEX IDX_6970EB0F70574616 ON reviews (reviewer_id)');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A4BBC2705 FOREIGN KEY (version_id) REFERENCES document_versions (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962AF675F31B FOREIGN KEY (author_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A727ACA70 FOREIGN KEY (parent_id) REFERENCES comments (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0F4BBC2705 FOREIGN KEY (version_id) REFERENCES document_versions (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reviews ADD CONSTRAINT FK_6970EB0F70574616 FOREIGN KEY (reviewer_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments DROP CONSTRAINT FK_5F9E962A4BBC2705');
        $this->addSql('ALTER TABLE comments DROP CONSTRAINT FK_5F9E962AF675F31B');
        $this->addSql('ALTER TABLE comments DROP CONSTRAINT FK_5F9E962A727ACA70');
        $this->addSql('ALTER TABLE reviews DROP CONSTRAINT FK_6970EB0F4BBC2705');
        $this->addSql('ALTER TABLE reviews DROP CONSTRAINT FK_6970EB0F70574616');
        $this->addSql('DROP TABLE comments');
        $this->addSql('DROP TABLE reviews');
    }
}
