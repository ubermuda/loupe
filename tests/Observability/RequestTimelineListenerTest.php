<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\RecordedSpan;
use App\Observability\RequestTimeline;
use App\Observability\RequestTimelineListener;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestTimelineListenerTest extends TestCase
{
    public function test_it_attaches_each_recorded_interval_as_a_child_of_the_transaction(): void
    {
        $transaction = new Transaction(TransactionContext::make());
        $transaction->initSpanRecorder();
        $timeline = new RequestTimeline($this->hubWith(null));
        $timeline->record('app.boot', 100.25, 100.5);
        $timeline->record('session.read', 100.5, 100.75, null, ['session.mode' => 'locking']);

        $this->listener($timeline, $transaction)->attach($this->event(HttpKernelInterface::MAIN_REQUEST));

        $children = array_values(array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn (Span $span): bool => $span !== $transaction,
        ));
        self::assertCount(2, $children);
        self::assertSame('app.boot', $children[0]->getOp());
        self::assertSame(100.25, $children[0]->getStartTimestamp());
        self::assertSame(100.5, $children[0]->getEndTimestamp());
        self::assertSame($transaction->getSpanId(), $children[0]->getParentSpanId());
        self::assertSame('session.read', $children[1]->getOp());
        self::assertSame(['session.mode' => 'locking'], $children[1]->getData());
        self::assertSame([], $timeline->drain());
    }

    public function test_it_clears_the_recorded_intervals_when_the_hub_has_no_span(): void
    {
        $timeline = new RequestTimeline($this->hubWith(null));
        $timeline->record('app.boot', 1.0, 2.0);

        $this->listener($timeline, null)->attach($this->event(HttpKernelInterface::MAIN_REQUEST));

        self::assertSame([], $timeline->drain());
    }

    public function test_a_sub_request_attaches_nothing(): void
    {
        $transaction = new Transaction(TransactionContext::make());
        $transaction->initSpanRecorder();
        $timeline = new RequestTimeline($this->hubWith(null));
        $timeline->record('app.boot', 1.0, 2.0);

        $this->listener($timeline, $transaction)->attach($this->event(HttpKernelInterface::SUB_REQUEST));

        self::assertSame([$transaction], $transaction->getSpanRecorder()?->getSpans());
        self::assertCount(1, $timeline->drain());
    }

    public function test_it_records_the_boot_from_the_request_time_on_the_main_request(): void
    {
        $timeline = new RequestTimeline($this->hubWith(null));
        $event = $this->event(HttpKernelInterface::MAIN_REQUEST);
        $event->getRequest()->server->set('REQUEST_TIME_FLOAT', 100.25);

        $this->listener($timeline, null)->recordBoot($event);

        $recorded = $timeline->drain();
        self::assertCount(1, $recorded);
        self::assertSame('app.boot', $recorded[0]->op);
        self::assertSame(100.25, $recorded[0]->start);
        self::assertGreaterThan(100.25, $recorded[0]->end);
    }

    public function test_it_records_no_boot_on_a_sub_request(): void
    {
        $timeline = new RequestTimeline($this->hubWith(null));

        $this->listener($timeline, null)->recordBoot($this->event(HttpKernelInterface::SUB_REQUEST));

        self::assertSame([], $timeline->drain());
    }

    public function test_it_records_no_boot_without_a_request_time(): void
    {
        $timeline = new RequestTimeline($this->hubWith(null));
        $event = $this->event(HttpKernelInterface::MAIN_REQUEST);
        $event->getRequest()->server->remove('REQUEST_TIME_FLOAT');

        $this->listener($timeline, null)->recordBoot($event);

        self::assertSame([], $timeline->drain());
    }

    public function test_it_reads_a_numeric_string_request_time(): void
    {
        $timeline = new RequestTimeline($this->hubWith(null));
        $event = $this->event(HttpKernelInterface::MAIN_REQUEST);
        $event->getRequest()->server->set('REQUEST_TIME_FLOAT', '100.25');

        $this->listener($timeline, null)->recordBoot($event);

        $recorded = $timeline->drain();
        self::assertContainsOnlyInstancesOf(RecordedSpan::class, $recorded);
        self::assertSame(100.25, $recorded[0]->start ?? null);
    }

    private function listener(RequestTimeline $timeline, ?Span $span): RequestTimelineListener
    {
        return new RequestTimelineListener($timeline, $this->hubWith($span));
    }

    private function hubWith(?Span $span): HubInterface
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getSpan')->willReturn($span);

        return $hub;
    }

    private function event(int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/board'), $type);
    }
}
