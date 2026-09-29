<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\RecordedSpan;
use App\Observability\RequestTimeline;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class RequestTimelineTest extends TestCase
{
    public function test_a_span_with_no_hub_span_records_an_interval_around_the_work(): void
    {
        $timeline = new RequestTimeline($this->createStub(HubInterface::class));

        $before = microtime(true);
        $result = $timeline->span('db.connect', static fn (): string => 'connected', 'pgsql', ['a' => 1]);
        $after = microtime(true);

        self::assertSame('connected', $result);
        $recorded = $timeline->drain();
        self::assertCount(1, $recorded);
        self::assertSame('db.connect', $recorded[0]->op);
        self::assertSame('pgsql', $recorded[0]->description);
        self::assertSame(['a' => 1], $recorded[0]->data);
        self::assertGreaterThanOrEqual($before, $recorded[0]->start);
        self::assertGreaterThanOrEqual($recorded[0]->start, $recorded[0]->end);
        self::assertLessThanOrEqual($after, $recorded[0]->end);
        self::assertSame([], $timeline->drain());
    }

    public function test_a_span_records_its_interval_when_the_work_throws(): void
    {
        $timeline = new RequestTimeline($this->createStub(HubInterface::class));

        $this->expectException(\RuntimeException::class);

        try {
            $timeline->span('db.connect', static fn (): never => throw new \RuntimeException('refused'));
        } finally {
            self::assertCount(1, $timeline->drain());
        }
    }

    public function test_a_span_with_a_hub_span_opens_a_live_child_and_records_nothing(): void
    {
        $transaction = new Transaction(TransactionContext::make());
        $transaction->initSpanRecorder();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getSpan')->willReturn($transaction);
        $timeline = new RequestTimeline($hub);

        $timeline->span('db.connect', static fn (): null => null, 'pgsql', ['a' => 1]);

        $children = array_values(array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn ($span): bool => $span !== $transaction,
        ));
        self::assertCount(1, $children);
        self::assertSame('db.connect', $children[0]->getOp());
        self::assertSame('pgsql', $children[0]->getDescription());
        self::assertSame(['a' => 1], $children[0]->getData());
        self::assertNotNull($children[0]->getEndTimestamp());
        self::assertSame([], $timeline->drain());
    }

    public function test_reset_clears_the_recorded_intervals(): void
    {
        $timeline = new RequestTimeline($this->createStub(HubInterface::class));
        $timeline->record('app.boot', 1.0, 2.0);
        $timeline->span('db.connect', static fn (): null => null);

        $timeline->reset();

        self::assertSame([], $timeline->drain());
    }

    public function test_record_keeps_the_given_interval(): void
    {
        $timeline = new RequestTimeline($this->createStub(HubInterface::class));

        $timeline->record('app.boot', 1.5, 2.5, 'kernel');

        self::assertEquals([new RecordedSpan('app.boot', 1.5, 2.5, 'kernel')], $timeline->drain());
    }
}
