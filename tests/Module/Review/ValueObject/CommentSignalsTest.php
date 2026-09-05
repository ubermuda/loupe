<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\ValueObject;

use App\Module\Review\ValueObject\CommentSignals;
use PHPUnit\Framework\TestCase;

final class CommentSignalsTest extends TestCase
{
    public function test_addressed_threads_are_open(): void
    {
        $signals = new CommentSignals(pendingThreadCount: 1, addressedThreadCount: 2, resolvedThreadCount: 3);

        self::assertSame(3, $signals->openThreadCount());
        self::assertSame(6, $signals->threadCount());
    }

    public function test_a_document_with_no_threads_answers_nothing(): void
    {
        $signals = new CommentSignals();

        self::assertFalse($signals->allThreadsAnswered());
        self::assertFalse($signals->hasAddressedThreads());
        self::assertFalse($signals->hasOrphanedThreads());
        self::assertSame(0, $signals->threadCount());
    }

    public function test_one_pending_thread_stops_all_answered(): void
    {
        $signals = new CommentSignals(pendingThreadCount: 1, resolvedThreadCount: 9);

        self::assertFalse($signals->allThreadsAnswered());
    }

    public function test_resolved_threads_alone_count_as_answered(): void
    {
        $signals = new CommentSignals(resolvedThreadCount: 2);

        self::assertTrue($signals->allThreadsAnswered());
        self::assertFalse($signals->hasAddressedThreads());
    }

    /**
     * `orphaned` is a flag beside the status, not a status, so the same thread
     * fills a status bucket and the orphaned bucket. Adding those two buckets
     * would report one thread as two.
     */
    public function test_an_addressed_orphan_waits_once_not_twice(): void
    {
        $signals = new CommentSignals(addressedThreadCount: 3, orphanedThreadCount: 3);

        self::assertSame(3, $signals->waitingThreadCount());
    }

    public function test_a_pending_orphan_waits_because_nobody_acted_and_the_anchor_is_gone(): void
    {
        $signals = new CommentSignals(pendingThreadCount: 1, orphanedThreadCount: 1, pendingOrphanedThreadCount: 1);

        self::assertSame(1, $signals->waitingThreadCount());
        self::assertTrue($signals->hasWaitingThreads());
    }

    public function test_an_anchored_pending_thread_waits_for_the_agent_not_the_reader(): void
    {
        $signals = new CommentSignals(pendingThreadCount: 4);

        self::assertSame(0, $signals->waitingThreadCount());
        self::assertFalse($signals->hasWaitingThreads());
    }

    public function test_a_resolved_thread_waits_for_nobody_even_when_orphaned(): void
    {
        $signals = new CommentSignals(resolvedThreadCount: 2, orphanedThreadCount: 2);

        self::assertSame(0, $signals->waitingThreadCount());
        self::assertFalse($signals->hasWaitingThreads());
    }
}
