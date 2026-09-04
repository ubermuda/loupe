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
}
