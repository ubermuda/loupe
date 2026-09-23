<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;

/** One row of the worker run list, with the duration the row shows that the entity does not hold. */
final readonly class WorkerRunListItem
{
    public WorkerRunState $state;

    /** Null when the run never started, or closed with no reported end. */
    public ?int $durationSeconds;

    /**
     * @param \DateTimeImmutable         $now     the end of a run that is still open
     * @param list<WorkerRunStateChange> $history the states the run reached, oldest first
     */
    public function __construct(
        public WorkerRun $run,
        \DateTimeImmutable $now,
        public array $history,
    ) {
        $this->state = $run->state;
        $end = $run->state->isOpen() ? $now : $run->endedAt;
        $this->durationSeconds = null === $run->startedAt || null === $end
            ? null
            : max(0, $end->getTimestamp() - $run->startedAt->getTimestamp());
    }

    /**
     * The duration as a person reads it, such as `21s`, `3m 12s` or `1h 04m`,
     * or null when there is none to show.
     *
     * English-only, like the documents list's relative time. The app ships one
     * locale, so a locale-aware duration formatter would be premature.
     */
    public function duration(): ?string
    {
        $seconds = $this->durationSeconds;
        if (null === $seconds) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }

        return intdiv($seconds, 3600).'h '.\sprintf('%02d', intdiv($seconds % 3600, 60)).'m';
    }
}
