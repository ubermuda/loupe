<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912191354 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Settle the unforwardable outbox rows and drop forwardable from the drain index';
    }

    /**
     * A row that a collect-only widget token wrote must never reach an agent.
     * The drain filtered it out by `forwardable`, and it no longer does, so
     * settle every such row first. The column itself stays, because the
     * deployed image still reads it and a rollback runs this migration.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE outbox_events SET published_at = NOW() WHERE forwardable = false AND published_at IS NULL');
        $this->addSql('DROP INDEX idx_outbox_events_drain');
        $this->addSql('CREATE INDEX idx_outbox_events_drain ON outbox_events (published_at, next_attempt_at)');
    }

    /** The drain never published an unforwardable row, so this stamp is the only one to clear. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE outbox_events SET published_at = NULL WHERE forwardable = false');
        $this->addSql('DROP INDEX idx_outbox_events_drain');
        $this->addSql('CREATE INDEX idx_outbox_events_drain ON outbox_events (published_at, forwardable, next_attempt_at)');
    }
}
