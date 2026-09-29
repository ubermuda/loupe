<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\ConnectTimingMiddleware;
use App\Observability\RequestTimeline;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class ConnectTimingMiddlewareTest extends TestCase
{
    public function test_with_no_hub_span_it_returns_the_inner_connection_and_records_the_connect(): void
    {
        $connection = $this->createStub(DriverConnection::class);
        $timeline = new RequestTimeline($this->hubWith(null));

        $driver = new ConnectTimingMiddleware($timeline)->wrap($this->driverReturning($connection));

        self::assertSame($connection, $driver->connect([]));
        $recorded = $timeline->drain();
        self::assertCount(1, $recorded);
        self::assertSame('db.connect', $recorded[0]->op);
    }

    public function test_with_a_hub_span_it_returns_the_inner_connection_and_opens_a_live_span(): void
    {
        $connection = $this->createStub(DriverConnection::class);
        $transaction = new Transaction(TransactionContext::make());
        $transaction->initSpanRecorder();
        $timeline = new RequestTimeline($this->hubWith($transaction));

        $driver = new ConnectTimingMiddleware($timeline)->wrap($this->driverReturning($connection));

        self::assertSame($connection, $driver->connect([]));
        $ops = array_map(static fn (Span $span): ?string => $span->getOp(), $transaction->getSpanRecorder()?->getSpans() ?? []);
        self::assertContains('db.connect', $ops);
        self::assertSame([], $timeline->drain());
    }

    private function driverReturning(DriverConnection $connection): Driver
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        return $driver;
    }

    private function hubWith(?Span $span): HubInterface
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getSpan')->willReturn($span);

        return $hub;
    }
}
