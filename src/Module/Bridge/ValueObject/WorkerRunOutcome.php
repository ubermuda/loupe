<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/**
 * How a worker run ended, read off the exit code and the result flag the bridge reported.
 *
 * The values reach the page as query-string words, so they stay stable.
 */
enum WorkerRunOutcome: string
{
    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** The process never started, so the run carries a failure reason instead. */
    case NotStarted = 'not-started';

    /** The process exited cleanly without its final result. */
    case NoResult = 'no-result';

    /** A null result flag comes from an older bridge, so a clean exit then still reads as a success. */
    public static function fromRun(?int $exitCode, ?bool $hasResult): self
    {
        return match (true) {
            null === $exitCode => self::NotStarted,
            0 !== $exitCode => self::Failed,
            false === $hasResult => self::NoResult,
            default => self::Succeeded,
        };
    }

    /** Spelled out rather than built from the value, because the values are kebab-case and the keys are not. */
    public function translationKey(): string
    {
        return match ($this) {
            self::Succeeded => 'bridge.worker_runs.outcome.succeeded',
            self::Failed => 'bridge.worker_runs.outcome.failed',
            self::NotStarted => 'bridge.worker_runs.outcome.not_started',
            self::NoResult => 'bridge.worker_runs.outcome.no_result',
        };
    }

    /** The .lp-status-chip modifier for this outcome. The chip palette is shared, so the names differ. */
    public function chipModifier(): string
    {
        return match ($this) {
            self::Succeeded => 'ok',
            self::Failed, self::NoResult => 'failed',
            self::NotStarted => 'pending',
        };
    }
}
