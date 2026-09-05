<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * What the comments on one document version say about it, counted by status.
 *
 * Every count is a thread count. A reply carries neither a status of its own nor
 * an anchor of its own, so the tally behind this object reads thread roots only.
 *
 * The three status counts partition the threads. `orphanedThreadCount` cuts
 * across all three, because a lost anchor is a flag beside the status rather
 * than a status, so never add it to one of them.
 */
final readonly class CommentSignals
{
    public function __construct(
        public int $pendingThreadCount = 0,
        public int $addressedThreadCount = 0,
        public int $resolvedThreadCount = 0,
        public int $orphanedThreadCount = 0,
        public int $pendingOrphanedThreadCount = 0,
    ) {
    }

    public function threadCount(): int
    {
        return $this->pendingThreadCount + $this->addressedThreadCount + $this->resolvedThreadCount;
    }

    /**
     * Addressed threads stay open: the agent that claims it acted is not the
     * reader who agrees the thread is finished.
     */
    public function openThreadCount(): int
    {
        return $this->pendingThreadCount + $this->addressedThreadCount;
    }

    public function hasAddressedThreads(): bool
    {
        return $this->addressedThreadCount > 0;
    }

    /** A document with no threads answers nothing, so it reports false. */
    public function allThreadsAnswered(): bool
    {
        return $this->threadCount() > 0 && 0 === $this->pendingThreadCount;
    }

    public function hasOrphanedThreads(): bool
    {
        return $this->orphanedThreadCount > 0;
    }

    /**
     * Threads that wait for the reader, each counted once. The sum below is the
     * only place that says which threads those are, so widening or narrowing
     * the signal means changing one expression here.
     *
     * Addressed means the agent claims it acted and nobody confirmed. A pending
     * orphan means nobody acted and the anchor is gone. The two are disjoint,
     * because a thread carries one status. The counts the repository supplies
     * are plain tallies, and none of them carries this decision.
     */
    public function waitingThreadCount(): int
    {
        return $this->addressedThreadCount + $this->pendingOrphanedThreadCount;
    }

    public function hasWaitingThreads(): bool
    {
        return $this->waitingThreadCount() > 0;
    }
}
