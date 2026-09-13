<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913215923 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the bridge worker run table with its full-text search vector';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_runs (id UUID NOT NULL, search_vector TSVECTOR DEFAULT NULL, bridge_id UUID NOT NULL, card_id UUID NOT NULL, card_number INT NOT NULL, rule_name VARCHAR(100) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, exit_code INT DEFAULT NULL, failure_reason TEXT DEFAULT NULL, output TEXT NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8DAF6488166D1F9C ON bridge_worker_runs (project_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_runs_project_received ON bridge_worker_runs (project_id, received_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, card_id, started_at)');

        // Written by hand because DBAL's Postgres platform emits no USING clause:
        // a B-tree index on a tsvector is built happily and never used by @@.
        $this->addSql('CREATE INDEX idx_bridge_worker_runs_search_vector ON bridge_worker_runs USING gin (search_vector)');

        $this->addSql('ALTER TABLE bridge_worker_runs ADD CONSTRAINT FK_8DAF6488166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP CONSTRAINT FK_8DAF6488166D1F9C');
        $this->addSql('DROP TABLE bridge_worker_runs');
    }
}
