<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunOutcome;

/** One row of the worker run list, with the two values the row shows that the entity does not hold. */
final readonly class WorkerRunListItem
{
    public WorkerRunOutcome $outcome;

    public int $durationSeconds;

    public function __construct(
        public WorkerRun $run,
    ) {
        $this->outcome = WorkerRunOutcome::fromRun($run->exitCode, $run->hasResult);
        $this->durationSeconds = max(0, $run->endedAt->getTimestamp() - $run->startedAt->getTimestamp());
    }

    /**
     * The duration as a person reads it, such as `21s`, `3m 12s` or `1h 04m`.
     *
     * English-only, like the documents list's relative time. The app ships one
     * locale, so a locale-aware duration formatter would be premature.
     */
    public function duration(): string
    {
        $seconds = $this->durationSeconds;
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }

        return intdiv($seconds, 3600).'h '.\sprintf('%02d', intdiv($seconds % 3600, 60)).'m';
    }
}
