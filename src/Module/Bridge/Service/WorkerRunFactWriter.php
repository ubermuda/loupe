<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the fact row of each run from the run, its usage rows and its tool
 * call rows. The four tool call columns stay null for a run with no rows. The
 * migration that made the table holds a frozen copy of this select.
 */
final readonly class WorkerRunFactWriter
{
    private const string UPSERT_SQL = <<<'SQL'
        INSERT INTO bridge_worker_run_facts (
            run_id, project_id, subject_type, subject_id, card_number, kind, work_kind, rule_id,
            experiment, variant, model, bridge_id, outcome, started_at, ended_at, received_at,
            duration_ms, cost_micro_usd, tokens_in, tokens_out, tokens_cache_read, tokens_cache_write,
            usage_source, tool_time_ms, model_time_ms, tool_calls, failed_calls, longest_call_ms,
            idle_gap_ms, subagent_ms, peak_context_tokens
        )
        SELECT
            r.id,
            r.project_id,
            r.subject_type,
            r.subject_id,
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
            r.usage_source,
            r.tool_time_ms,
            CASE WHEN r.started_at IS NULL OR r.ended_at IS NULL OR r.tool_time_ms IS NULL OR r.idle_gap_ms IS NULL THEN NULL
                ELSE GREATEST(0, (EXTRACT(EPOCH FROM (r.ended_at - r.started_at)) * 1000)::bigint - r.tool_time_ms - r.idle_gap_ms) END,
            tc.tool_calls,
            tc.failed_calls,
            tc.longest_call_ms,
            r.idle_gap_ms,
            tc.subagent_ms,
            r.peak_context_tokens
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
        LEFT JOIN (
            SELECT
                run_id,
                COUNT(*) AS tool_calls,
                COUNT(*) FILTER (WHERE is_error) AS failed_calls,
                MAX(duration_ms) AS longest_call_ms,
                COALESCE(SUM(duration_ms) FILTER (WHERE tool IN ('Agent', 'Task') AND NOT in_subagent), 0)::bigint AS subagent_ms
            FROM bridge_worker_run_tool_calls
            WHERE run_id IN (:ids)
            GROUP BY run_id
        ) tc ON tc.run_id = r.id
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
            usage_source = EXCLUDED.usage_source,
            tool_time_ms = EXCLUDED.tool_time_ms,
            model_time_ms = EXCLUDED.model_time_ms,
            tool_calls = EXCLUDED.tool_calls,
            failed_calls = EXCLUDED.failed_calls,
            longest_call_ms = EXCLUDED.longest_call_ms,
            idle_gap_ms = EXCLUDED.idle_gap_ms,
            subagent_ms = EXCLUDED.subagent_ms,
            peak_context_tokens = EXCLUDED.peak_context_tokens
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

        $params = ['ids' => array_map(static fn (Uuid $id): string => (string) $id, $runIds)];
        $types = ['ids' => ArrayParameterType::STRING];

        // The lock waits for a writer that holds a run, and the upsert after it
        // reads that writer's commit, so an older read never overwrites a newer row.
        $this->connection->transactional(function () use ($params, $types): void {
            $this->connection->executeQuery('SELECT id FROM bridge_worker_runs WHERE id IN (:ids) ORDER BY id FOR UPDATE', $params, $types);
            $this->connection->executeStatement(self::UPSERT_SQL, $params, $types);
        });
    }
}
