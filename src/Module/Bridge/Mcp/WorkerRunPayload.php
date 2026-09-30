<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\BridgeCommandState;

/**
 * Shapes worker runs and bridge commands for an MCP tool result.
 *
 * @phpstan-type PendingCommand array{commandId: string, kind: string, state: string}
 * @phpstan-type WorkerRunRow array{runId: string, runKey: ?string, cardId: string, cardNumber: int, rule: string, state: string, sessionId: ?string, resumeIndex: ?int, resumeCap: ?int, startedAt: ?string, endedAt: ?string, exitCode: ?int, bridgeId: ?string, pendingCommand: PendingCommand|null, reason: ?string}
 * @phpstan-type WorkerRunDetail array{runId: string, runKey: ?string, cardId: string, cardNumber: int, rule: string, state: string, sessionId: ?string, resumeIndex: ?int, resumeCap: ?int, startedAt: ?string, endedAt: ?string, exitCode: ?int, bridgeId: ?string, pendingCommand: PendingCommand|null, reason: ?string, continuesRunId: ?string, receivedAt: string, triggerEventType: ?string, triggerForge: ?string, triggerRepository: ?string, triggerPullRequestNumber: ?int, triggerHeadSha: ?string, triggerReason: ?string, failureReason: ?string, output: string, stateChanges: list<array{state: string, at: string}>}
 * @phpstan-type BridgeCommandRow array{commandId: string, runId: string, kind: string, state: string, reason: ?string, requestedAt: string, expiresAt: string, settledAt: ?string}
 */
final readonly class WorkerRunPayload
{
    public const int MAX_REASON_LENGTH = 300;

    /** @return WorkerRunRow */
    public function forRow(WorkerRun $run, ?BridgeCommand $pending): array
    {
        return [
            'runId' => (string) $run->id,
            'runKey' => $run->runKey?->toRfc4122(),
            'cardId' => (string) $run->cardId,
            'cardNumber' => $run->cardNumber,
            'rule' => $run->ruleName,
            'state' => $run->state->value,
            'sessionId' => $run->sessionId?->toRfc4122(),
            'resumeIndex' => $run->resumeIndex,
            'resumeCap' => $run->resumeCap,
            'startedAt' => $run->startedAt?->format(\DATE_ATOM),
            'endedAt' => $run->endedAt?->format(\DATE_ATOM),
            'exitCode' => $run->exitCode,
            'bridgeId' => $run->bridgeId?->toRfc4122(),
            'pendingCommand' => null !== $pending && BridgeCommandState::Pending === $pending->state
                ? ['commandId' => (string) $pending->id, 'kind' => $pending->kind->value, 'state' => $pending->state->value]
                : null,
            'reason' => self::reasonOf($run),
        ];
    }

    /**
     * @param list<WorkerRunStateChange> $stateChanges
     *
     * @return WorkerRunDetail
     */
    public function forDetail(WorkerRun $run, ?BridgeCommand $pending, array $stateChanges): array
    {
        return [
            ...$this->forRow($run, $pending),
            'continuesRunId' => null === $run->continuesRun ? null : (string) $run->continuesRun->id,
            'receivedAt' => $run->receivedAt->format(\DATE_ATOM),
            'triggerEventType' => $run->triggerEventType,
            'triggerForge' => $run->triggerForge,
            'triggerRepository' => $run->triggerRepository,
            'triggerPullRequestNumber' => $run->triggerPullRequestNumber,
            'triggerHeadSha' => $run->triggerHeadSha,
            'triggerReason' => $run->triggerReason,
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
