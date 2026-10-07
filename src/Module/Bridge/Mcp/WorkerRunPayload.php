<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Command\ListWorkerRunReadingsView;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\ValueObject\BridgeCommandState;

/**
 * Shapes worker runs and bridge commands for an MCP tool result.
 *
 * @phpstan-type PendingCommand array{commandId: string, kind: string, state: string}
 * @phpstan-type UsageRow array{model: string, source: string, inputTokens: int, outputTokens: int, cacheReadTokens: int, cacheWriteTokens: int, costUsd: ?string}
 * @phpstan-type RunMetrics array{durationMs: ?int, costUsd: ?float, tokensIn: ?int, tokensOut: ?int, tokensCacheRead: ?int, tokensCacheWrite: ?int, toolTimeMs: ?int, modelTimeMs: ?int, toolCalls: ?int, failedCalls: ?int, longestCallMs: ?int, idleGapMs: ?int, subagentMs: ?int}
 * @phpstan-type WorkerRunRow array{runId: string, runKey: ?string, kind: string, subjectType: string, subjectId: string, cardNumber: ?int, workRequestId: ?string, workKind: ?string, ruleId: ?string, state: string, sessionId: ?string, startedAt: ?string, endedAt: ?string, exitCode: ?int, bridgeId: ?string, pendingCommand: PendingCommand|null, reason: ?string, usage: list<UsageRow>, model: ?string, experiment: ?string, variant: ?string, metrics: RunMetrics|null}
 * @phpstan-type WorkerRunDetail array{runId: string, runKey: ?string, kind: string, subjectType: string, subjectId: string, cardNumber: ?int, workRequestId: ?string, workKind: ?string, ruleId: ?string, state: string, sessionId: ?string, startedAt: ?string, endedAt: ?string, exitCode: ?int, bridgeId: ?string, pendingCommand: PendingCommand|null, reason: ?string, usage: list<UsageRow>, model: ?string, experiment: ?string, variant: ?string, metrics: RunMetrics|null, continuesRunId: ?string, receivedAt: string, failureReason: ?string, output: string, stateChanges: list<array{state: string, at: string}>}
 * @phpstan-type BridgeCommandRow array{commandId: string, runId: string, kind: string, state: string, reason: ?string, requestedAt: string, expiresAt: string, settledAt: ?string}
 */
final readonly class WorkerRunPayload
{
    public const int MAX_REASON_LENGTH = 300;

    /** @return WorkerRunRow */
    public function forRow(WorkerRun $run, ?BridgeCommand $pending, ListWorkerRunReadingsView $readings): array
    {
        $fact = $readings->facts[(string) $run->id] ?? null;

        return [
            'runId' => (string) $run->id,
            'runKey' => $run->runKey?->toRfc4122(),
            'kind' => $run->kind->value,
            'subjectType' => $run->subjectType,
            'subjectId' => $run->subjectId->toRfc4122(),
            'cardNumber' => $run->cardNumber,
            'workRequestId' => $run->workRequestId?->toRfc4122(),
            'workKind' => $run->workKind,
            'ruleId' => $run->ruleId,
            'state' => $run->state->value,
            'sessionId' => $run->sessionId?->toRfc4122(),
            'startedAt' => $run->startedAt?->format(\DATE_ATOM),
            'endedAt' => $run->endedAt?->format(\DATE_ATOM),
            'exitCode' => $run->exitCode,
            'bridgeId' => $run->bridgeId?->toRfc4122(),
            'pendingCommand' => null !== $pending && BridgeCommandState::Pending === $pending->state
                ? ['commandId' => (string) $pending->id, 'kind' => $pending->kind->value, 'state' => $pending->state->value]
                : null,
            'reason' => self::reasonOf($run),
            'usage' => array_map(static fn (WorkerRunUsage $usage): array => [
                'model' => $usage->model,
                'source' => $usage->source->value,
                'inputTokens' => $usage->inputTokens,
                'outputTokens' => $usage->outputTokens,
                'cacheReadTokens' => $usage->cacheReadTokens,
                'cacheWriteTokens' => $usage->cacheWriteTokens,
                'costUsd' => $usage->costUsd,
            ], $readings->usage[(string) $run->id] ?? []),
            'model' => $fact?->model,
            'experiment' => $run->experiment,
            'variant' => $run->variant,
            'metrics' => null === $fact ? null : self::metricsOf($fact),
        ];
    }

    /** @return RunMetrics */
    private static function metricsOf(WorkerRunFact $fact): array
    {
        return [
            'durationMs' => $fact->durationMs,
            'costUsd' => null === $fact->costMicroUsd ? null : $fact->costMicroUsd / 1_000_000.0,
            'tokensIn' => $fact->tokensIn,
            'tokensOut' => $fact->tokensOut,
            'tokensCacheRead' => $fact->tokensCacheRead,
            'tokensCacheWrite' => $fact->tokensCacheWrite,
            'toolTimeMs' => $fact->toolTimeMs,
            'modelTimeMs' => $fact->modelTimeMs,
            'toolCalls' => $fact->toolCalls,
            'failedCalls' => $fact->failedCalls,
            'longestCallMs' => $fact->longestCallMs,
            'idleGapMs' => $fact->idleGapMs,
            'subagentMs' => $fact->subagentMs,
        ];
    }

    /**
     * @param list<WorkerRunStateChange> $stateChanges
     *
     * @return WorkerRunDetail
     */
    public function forDetail(WorkerRun $run, ?BridgeCommand $pending, array $stateChanges, ListWorkerRunReadingsView $readings): array
    {
        return [
            ...$this->forRow($run, $pending, $readings),
            'continuesRunId' => null === $run->continuesRun || $run->continuesRun->project !== $run->project ? null : (string) $run->continuesRun->id,
            'receivedAt' => $run->receivedAt->format(\DATE_ATOM),
            'failureReason' => $run->failureReason,
            'output' => $run->output,
            'stateChanges' => array_map(
                static fn (WorkerRunStateChange $change): array => ['state' => $change->state->value, 'at' => $change->at->format(\DATE_ATOM)],
                $stateChanges,
            ),
        ];
    }

    /** @return BridgeCommandRow */
    public function forCommand(BridgeCommand $command): array
    {
        return [
            'commandId' => (string) $command->id,
            'runId' => (string) $command->workerRun->id,
            'kind' => $command->kind->value,
            'state' => $command->state->value,
            'reason' => $command->reason,
            'requestedAt' => $command->requestedAt->format(\DATE_ATOM),
            'expiresAt' => $command->expiresAt->format(\DATE_ATOM),
            'settledAt' => $command->settledAt?->format(\DATE_ATOM),
        ];
    }

    /** The failure reason, or else the last line of output with text, so a row says why the run ended. */
    public static function reasonOf(WorkerRun $run): ?string
    {
        if (null !== $run->failureReason && '' !== trim($run->failureReason)) {
            return mb_substr(trim($run->failureReason), 0, self::MAX_REASON_LENGTH);
        }

        $lines = array_filter(
            array_map(trim(...), preg_split('/\R/u', $run->output) ?: []),
            static fn (string $line): bool => '' !== $line,
        );
        $last = end($lines);

        return false === $last ? null : mb_substr($last, 0, self::MAX_REASON_LENGTH);
    }
}
