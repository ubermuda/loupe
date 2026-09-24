<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/**
 * Where a worker run stands, as the bridge last reported it or as the server
 * inferred it.
 *
 * The values reach the page as query-string words, so they stay stable.
 */
enum WorkerRunState: string
{
    case Queued = 'queued';

    /** A newer event for the same card took this run's place in the queue. */
    case Replaced = 'replaced';

    /** The ask the run waited on closed, so the bridge resumes the session. */
    case Resumed = 'resumed';

    case Skipped = 'skipped';

    case Running = 'running';

    /** The chain hit its cap. The bridge holds nothing more, and a person's move starts a new run. */
    case WaitingForPerson = 'waiting-for-person';

    case Dropped = 'dropped';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** The process never started, so the run carries a failure reason instead. */
    case NotStarted = 'not-started';

    /** The process exited cleanly without its final result. */
    case NoResult = 'no-result';

    /** The worker said its work still runs or is not done. */
    case Unfinished = 'unfinished';

    /** The worker said it cannot go on without a person. */
    case Blocked = 'blocked';

    /** The bridge used every resume the rule allows, and the run still did not finish. */
    case GaveUp = 'gave-up';

    /** The bridge went quiet. A later report from the bridge replaces this guess. */
    case TimedOut = 'timed-out';

    /** The bridge reconnected without the run, so the run can no longer end. */
    case Lost = 'lost';

    /** A null result flag or a null status comes from an older bridge, so a clean exit then still reads as a success. */
    public static function fromOutcome(?int $exitCode, ?bool $hasResult = null, ?string $resultStatus = null): self
    {
        return match (true) {
            null === $exitCode => self::NotStarted,
            0 !== $exitCode => self::Failed,
            false === $hasResult => self::NoResult,
            'blocked' === $resultStatus => self::Blocked,
            'unfinished' === $resultStatus => self::Unfinished,
            default => self::Succeeded,
        };
    }

    /** @return list<self> */
    public static function openStates(): array
    {
        return [self::Queued, self::Resumed, self::Running];
    }

    public function isOpen(): bool
    {
        return \in_array($this, self::openStates(), true);
    }

    /** How a worker process ended. Only these states carry an exit code or a failure reason. */
    public function isOutcome(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::NotStarted, self::NoResult, self::Unfinished, self::Blocked, self::GaveUp], true);
    }

    /** The server infers these on its own, and a bridge never reports them. */
    public function isInferred(): bool
    {
        return self::TimedOut === $this || self::Lost === $this;
    }

    /** A report whose state ranks lower than the run's current state never moves the run back. */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Resumed => 1,
            self::Running => 2,
            default => 3,
        };
    }

    /** Spelled out rather than built from the value, because the values are kebab-case and the keys are not. */
    public function translationKey(): string
    {
        return match ($this) {
            self::Queued => 'bridge.worker_runs.state.queued',
            self::Replaced => 'bridge.worker_runs.state.replaced',
            self::Resumed => 'bridge.worker_runs.state.resumed',
            self::Skipped => 'bridge.worker_runs.state.skipped',
            self::Running => 'bridge.worker_runs.state.running',
            self::WaitingForPerson => 'bridge.worker_runs.state.waiting_for_person',
            self::Dropped => 'bridge.worker_runs.state.dropped',
            self::Succeeded => 'bridge.worker_runs.state.succeeded',
            self::Failed => 'bridge.worker_runs.state.failed',
            self::NotStarted => 'bridge.worker_runs.state.not_started',
            self::NoResult => 'bridge.worker_runs.state.no_result',
            self::Unfinished => 'bridge.worker_runs.state.unfinished',
            self::Blocked => 'bridge.worker_runs.state.blocked',
            self::GaveUp => 'bridge.worker_runs.state.gave_up',
            self::TimedOut => 'bridge.worker_runs.state.timed_out',
            self::Lost => 'bridge.worker_runs.state.lost',
        };
    }

    /** The .lp-status-chip modifier for this state. The chip palette is shared, so the names differ. */
    public function chipModifier(): string
    {
        return match ($this) {
            self::Succeeded => 'ok',
            self::Failed, self::NoResult, self::GaveUp, self::Dropped, self::TimedOut, self::Lost => 'failed',
            // The bridge set these runs aside by design, so nothing waits and nothing failed.
            self::Replaced, self::Skipped => 'resolved',
            self::Queued, self::Resumed, self::Running, self::WaitingForPerson, self::NotStarted, self::Unfinished, self::Blocked => 'pending',
        };
    }
}
