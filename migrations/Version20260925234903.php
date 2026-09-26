<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925234903 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the worker run usage table and add the usage source to worker runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_run_usage (id UUID NOT NULL, card_id UUID NOT NULL, rule_name VARCHAR(100) NOT NULL, model VARCHAR(100) NOT NULL, source VARCHAR(20) NOT NULL, input_tokens BIGINT NOT NULL, output_tokens BIGINT NOT NULL, cache_read_tokens BIGINT NOT NULL, cache_write_tokens BIGINT NOT NULL, cost_usd NUMERIC(12, 6) DEFAULT NULL, run_id UUID DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C95EFC6184E3FEC4 ON bridge_worker_run_usage (run_id)');
        $this->addSql('CREATE INDEX IDX_C95EFC61166D1F9C ON bridge_worker_run_usage (project_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_run_usage_card ON bridge_worker_run_usage (project_id, card_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_usage_model ON bridge_worker_run_usage (run_id, model)');
        $this->addSql('ALTER TABLE bridge_worker_run_usage ADD CONSTRAINT FK_C95EFC6184E3FEC4 FOREIGN KEY (run_id) REFERENCES bridge_worker_runs (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_worker_run_usage ADD CONSTRAINT FK_C95EFC61166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD usage_source VARCHAR(20) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_usage DROP CONSTRAINT FK_C95EFC6184E3FEC4');
        $this->addSql('ALTER TABLE bridge_worker_run_usage DROP CONSTRAINT FK_C95EFC61166D1F9C');
        $this->addSql('DROP TABLE bridge_worker_run_usage');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP usage_source');
    }
}
