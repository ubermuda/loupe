<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914151443 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add a required, indexed session_id to bridge_worker_runs';
    }

    /** No default fills existing rows, so this fails on a table that already holds a run. */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD session_id UUID NOT NULL');
        $this->addSql('CREATE INDEX idx_bridge_worker_runs_session ON bridge_worker_runs (session_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bridge_worker_runs_session');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP session_id');
    }
}
