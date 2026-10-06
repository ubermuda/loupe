<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the fact row of each run from the run and its usage rows. The
 * migration that made the table holds a frozen copy of this select.
 */
final readonly class WorkerRunFactWriter
{
    private const string UPSERT_SQL = <<<'SQL'
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
            WHERE run_id IN (:ids)
            GROUP BY run_id
        ) u ON u.run_id = r.id
        LEFT JOIN LATERAL (
            SELECT m.model
            FROM bridge_worker_run_usage m
            WHERE m.run_id = r.id
            ORDER BY m.cost_usd DESC NULLS LAST, m.output_tokens DESC, m.model ASC
            LIMIT 1
        ) top ON true
        WHERE r.id IN (:ids)
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

    public function __construct(
        private Connection $connection,
    ) {
    }

    /** @param list<Uuid> $runIds */
    public function upsert(array $runIds): void
    {
        if ([] === $runIds) {
            return;
        }

        $this->connection->executeStatement(
            self::UPSERT_SQL,
            ['ids' => array_map(static fn (Uuid $id): string => (string) $id, $runIds)],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
