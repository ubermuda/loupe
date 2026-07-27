<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260727014442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create site_review_events table (durable outbox for review-submitted Mercure updates)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_review_events (id UUID NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, topic TEXT NOT NULL, payload TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, review_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8B5278213E2E969B ON site_review_events (review_id)');
        $this->addSql('ALTER TABLE site_review_events ADD CONSTRAINT FK_8B5278213E2E969B FOREIGN KEY (review_id) REFERENCES site_review_reviews (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site_review_events DROP CONSTRAINT FK_8B5278213E2E969B');
        $this->addSql('DROP TABLE site_review_events');
    }
}
