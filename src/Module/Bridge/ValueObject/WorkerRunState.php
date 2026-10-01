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

    /** The bridge runs the before command of the rule, and the agent has not started. */
    case Preparing = 'preparing';

    case Running = 'running';

    /** A person asked the bridge to stop the run, and the process is still ending. */
    case Stopping = 'stopping';

    /** A person stopped the run. It never enters the resume gate. */
    case Stopped = 'stopped';

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

    /** The worker said its work waits on the forge, such as checks on a pushed pull request. The bridge does not resume it. */
    case WaitingOnForge = 'waiting-on-forge';

    /** The bridge used every resume the rule allows, and the run still did not finish. */
    case GaveUp = 'gave-up';

    /** The bridge went quiet. A later report from the bridge replaces this guess. */
    case TimedOut = 'timed-out';

    /** The bridge reconnected without the run, so the run can no longer end. */
    case Lost = 'lost';

    /** The interactive session ended, or its card moved. A bridge never reports it. */
    case Closed = 'closed';

    /** A null result flag or a null status comes from an older bridge, so a clean exit then still reads as a success. */
    public static function fromOutcome(?int $exitCode, ?bool $hasResult = null, ?string $resultStatus = null): self
    {
        return match (true) {
            null === $exitCode => self::NotStarted,
            0 !== $exitCode => self::Failed,
            false === $hasResult => self::NoResult,
            'blocked' === $resultStatus => self::Blocked,
            'unfinished' === $resultStatus => self::Unfinished,
            'waiting' === $resultStatus => self::WaitingOnForge,
            default => self::Succeeded,
        };
    }

    /** @return list<self> */
    public static function openStates(): array
    {
        return [self::Queued, self::Resumed, self::Preparing, self::Running, self::Stopping];
    }

    public function isOpen(): bool
    {
        return \in_array($this, self::openStates(), true);
    }

    /** @return list<self> */
    public static function stoppableStates(): array
    {
        return [self::Queued, self::Resumed, self::Preparing, self::Running];
    }

    /** @return list<self> */
    public static function resumableStates(): array
    {
        return [self::Blocked, self::GaveUp, self::Failed, self::NoResult, self::Unfinished, self::TimedOut, self::Lost, self::Stopped, self::WaitingForPerson];
    }

    public function isStoppable(): bool
    {
        return \in_array($this, self::stoppableStates(), true);
    }

    public function isResumable(): bool
    {
        return \in_array($this, self::resumableStates(), true);
    }

    /** How a worker process ended. Only these states carry an exit code or a failure reason. */
    public function isOutcome(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::NotStarted, self::NoResult, self::Unfinished, self::Blocked, self::WaitingOnForge, self::GaveUp], true);
    }

    /** The outcomes that warn on the card while they are its latest. */
    public function isWarning(): bool
    {
        return self::GaveUp === $this || self::Blocked === $this;
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
            self::Preparing => 2,
            self::Running => 3,
            self::Stopping => 4,
            default => 5,
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
            self::Preparing => 'bridge.worker_runs.state.preparing',
            self::Running => 'bridge.worker_runs.state.running',
            self::Stopping => 'bridge.worker_runs.state.stopping',
            self::Stopped => 'bridge.worker_runs.state.stopped',
            self::WaitingForPerson => 'bridge.worker_runs.state.waiting_for_person',
            self::Dropped => 'bridge.worker_runs.state.dropped',
            self::Succeeded => 'bridge.worker_runs.state.succeeded',
            self::Failed => 'bridge.worker_runs.state.failed',
            self::NotStarted => 'bridge.worker_runs.state.not_started',
            self::NoResult => 'bridge.worker_runs.state.no_result',
            self::Unfinished => 'bridge.worker_runs.state.unfinished',
            self::Blocked => 'bridge.worker_runs.state.blocked',
            self::WaitingOnForge => 'bridge.worker_runs.state.waiting_on_forge',
            self::GaveUp => 'bridge.worker_runs.state.gave_up',
            self::TimedOut => 'bridge.worker_runs.state.timed_out',
            self::Lost => 'bridge.worker_runs.state.lost',
            self::Closed => 'bridge.worker_runs.state.closed',
        };
    }

    /** The .lp-status-chip modifier for this state. The chip palette is shared, so the names differ. */
    public function chipModifier(): string
    {
        return match ($this) {
            self::Succeeded, self::Closed => 'ok',
            self::Failed, self::NoResult, self::GaveUp, self::Dropped, self::TimedOut, self::Lost => 'failed',
            // The bridge or a person set these runs aside by design, so nothing waits and nothing failed.
            self::Replaced, self::Skipped, self::Stopped => 'resolved',
            self::Queued, self::Resumed, self::Preparing, self::Running, self::Stopping, self::WaitingForPerson, self::NotStarted, self::Unfinished, self::Blocked, self::WaitingOnForge => 'pending',
        };
    }
}
