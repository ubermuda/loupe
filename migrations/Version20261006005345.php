<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The backfill is an upsert, so a second run changes nothing. WorkerRunFactWriter
 * holds the runtime copy of the same select, and this copy stays as it ran.
 */
final class Version20261006005345 extends AbstractMigration
{
    public const string BACKFILL_SQL = <<<'SQL'
        INSERT INTO bridge_worker_run_facts (
            run_id, project_id, subject_type, subject_id, card_number, kind, work_kind, rule_id,
            experiment, variant, model, bridge_id, outcome, started_at, ended_at, received_at,
            duration_ms, cost_micro_usd, tokens_in, tokens_out, tokens_cache_read, tokens_cache_write,
            usage_source
        )
        SELECT
            r.id,
            r.project_id,
            'card',
            r.card_id,
            r.card_number,
            r.kind,
            r.work_kind,
            r.rule_id,
            r.experiment,
            r.variant,
            top.model,
            r.bridge_id,
            r.state,
            r.started_at,
            r.ended_at,
            r.received_at,
            (EXTRACT(EPOCH FROM (r.ended_at - r.started_at)) * 1000)::bigint,
            CASE WHEN r.usage_source IS NULL OR COALESCE(u.unpriced, false) THEN NULL ELSE COALESCE(u.cost_micro_usd, 0) END,
            CASE WHEN r.usage_source IS NULL THEN NULL ELSE COALESCE(u.tokens_in, 0) END,
            CASE WHEN r.usage_source IS NULL THEN NULL ELSE COALESCE(u.tokens_out, 0) END,
            CASE WHEN r.usage_source IS NULL THEN NULL ELSE COALESCE(u.tokens_cache_read, 0) END,
            CASE WHEN r.usage_source IS NULL THEN NULL ELSE COALESCE(u.tokens_cache_write, 0) END,
            r.usage_source
        FROM bridge_worker_runs r
        LEFT JOIN (
            SELECT
                run_id,
                BOOL_OR(cost_usd IS NULL) AS unpriced,
                SUM(ROUND(cost_usd * 1000000))::bigint AS cost_micro_usd,
                SUM(input_tokens)::bigint AS tokens_in,
                SUM(output_tokens)::bigint AS tokens_out,
                SUM(cache_read_tokens)::bigint AS tokens_cache_read,
                SUM(cache_write_tokens)::bigint AS tokens_cache_write
            FROM bridge_worker_run_usage
            WHERE run_id IS NOT NULL
            GROUP BY run_id
        ) u ON u.run_id = r.id
        LEFT JOIN LATERAL (
            SELECT m.model
            FROM bridge_worker_run_usage m
            WHERE m.run_id = r.id
            ORDER BY m.cost_usd DESC NULLS LAST, m.output_tokens DESC, m.model ASC
            LIMIT 1
        ) top ON true
        ON CONFLICT (run_id) DO UPDATE SET
            project_id = EXCLUDED.project_id,
            subject_type = EXCLUDED.subject_type,
            subject_id = EXCLUDED.subject_id,
            card_number = EXCLUDED.card_number,
            kind = EXCLUDED.kind,
            work_kind = EXCLUDED.work_kind,
            rule_id = EXCLUDED.rule_id,
            experiment = EXCLUDED.experiment,
            variant = EXCLUDED.variant,
            model = EXCLUDED.model,
            bridge_id = EXCLUDED.bridge_id,
            outcome = EXCLUDED.outcome,
            started_at = EXCLUDED.started_at,
            ended_at = EXCLUDED.ended_at,
            received_at = EXCLUDED.received_at,
            duration_ms = EXCLUDED.duration_ms,
            cost_micro_usd = EXCLUDED.cost_micro_usd,
            tokens_in = EXCLUDED.tokens_in,
            tokens_out = EXCLUDED.tokens_out,
            tokens_cache_read = EXCLUDED.tokens_cache_read,
            tokens_cache_write = EXCLUDED.tokens_cache_write,
            usage_source = EXCLUDED.usage_source
        SQL;

    #[\Override]
    public function getDescription(): string
    {
        return 'Create the worker run fact table, one row per run, and fill it from the runs and their usage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_run_facts (run_id UUID NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_id UUID NOT NULL, card_number INT DEFAULT NULL, kind VARCHAR(20) NOT NULL, work_kind VARCHAR(100) DEFAULT NULL, rule_id VARCHAR(100) DEFAULT NULL, experiment VARCHAR(64) DEFAULT NULL, variant VARCHAR(64) DEFAULT NULL, model VARCHAR(100) DEFAULT NULL, bridge_id UUID DEFAULT NULL, outcome VARCHAR(20) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, duration_ms BIGINT DEFAULT NULL, cost_micro_usd BIGINT DEFAULT NULL, tokens_in BIGINT DEFAULT NULL, tokens_out BIGINT DEFAULT NULL, tokens_cache_read BIGINT DEFAULT NULL, tokens_cache_write BIGINT DEFAULT NULL, usage_source VARCHAR(20) DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (run_id))');
        $this->addSql('CREATE INDEX IDX_82B1AE8A166D1F9C ON bridge_worker_run_facts (project_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_run_facts_project_ended ON bridge_worker_run_facts (project_id, ended_at)');
        $this->addSql('CREATE INDEX idx_bridge_worker_run_facts_subject ON bridge_worker_run_facts (project_id, subject_type, subject_id)');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD CONSTRAINT FK_82B1AE8A166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql(self::BACKFILL_SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_worker_run_facts');
    }
}
