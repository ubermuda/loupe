<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006164411 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the worker run tool call table, and add the timing columns to the runs and the fact rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_run_tool_calls (id UUID NOT NULL, seq INT NOT NULL, tool VARCHAR(64) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, duration_ms BIGINT DEFAULT NULL, is_error BOOLEAN DEFAULT NULL, in_subagent BOOLEAN NOT NULL, background_id VARCHAR(64) DEFAULT NULL, waits_on VARCHAR(64) DEFAULT NULL, signatures JSON NOT NULL, full_text TEXT DEFAULT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D9017C8884E3FEC4 ON bridge_worker_run_tool_calls (run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_tool_call_seq ON bridge_worker_run_tool_calls (run_id, seq)');
        $this->addSql('ALTER TABLE bridge_worker_run_tool_calls ADD CONSTRAINT FK_D9017C8884E3FEC4 FOREIGN KEY (run_id) REFERENCES bridge_worker_runs (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD tool_time_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD idle_gap_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD tool_time_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD model_time_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD tool_calls INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD failed_calls INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD longest_call_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD idle_gap_ms BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD subagent_ms BIGINT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_worker_run_tool_calls');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP tool_time_ms');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP idle_gap_ms');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP tool_time_ms');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP model_time_ms');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP tool_calls');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP failed_calls');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP longest_call_ms');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP idle_gap_ms');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP subagent_ms');
    }
}
