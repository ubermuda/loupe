<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728202909 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add retry bookkeeping to the site-review outbox';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_events ADD publish_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE site_review_events ADD next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE site_review_events ADD last_publish_error TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_site_review_events_drain ON site_review_events (published_at, forwardable, next_attempt_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_site_review_events_drain');
        $this->addSql('ALTER TABLE site_review_events DROP publish_attempts');
        $this->addSql('ALTER TABLE site_review_events DROP next_attempt_at');
        $this->addSql('ALTER TABLE site_review_events DROP last_publish_error');
    }
}
