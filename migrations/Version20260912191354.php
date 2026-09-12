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
        return 'Drop forwardable from the outbox drain index';
    }

    /**
     * The drain no longer filters on `forwardable`, so the column earns no place
     * in the index. The column itself stays, because the deployed image still
     * reads it and a rollback runs this migration.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_outbox_events_drain');
        $this->addSql('CREATE INDEX idx_outbox_events_drain ON outbox_events (published_at, next_attempt_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_outbox_events_drain');
        $this->addSql('CREATE INDEX idx_outbox_events_drain ON outbox_events (published_at, forwardable, next_attempt_at)');
    }
}
