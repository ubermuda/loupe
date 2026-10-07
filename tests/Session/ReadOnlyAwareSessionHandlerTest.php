<?php

declare(strict_types=1);

namespace App\Tests\Session;

use App\Observability\RecordedSpan;
use App\Observability\RequestTimeline;
use App\Session\ReadOnlyAwareSessionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ReadOnlyAwareSessionHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{Request|null, 'locking'|'nonLocking'}>
     */
    public static function requests(): iterable
    {
        yield 'no main request' => [null, 'locking'];
        yield 'GET' => [Request::create('/board'), 'nonLocking'];
        yield 'HEAD' => [Request::create('/board', Request::METHOD_HEAD), 'nonLocking'];
        yield 'POST' => [Request::create('/board', Request::METHOD_POST), 'locking'];
        yield 'DELETE' => [Request::create('/board', Request::METHOD_DELETE), 'locking'];
        yield 'GET on a route marked for another frame' => [self::marked('board-frame', Request::create('/board'), 'other-frame'), 'nonLocking'];
        yield 'GET on a frame route with no frame header' => [self::marked('board-frame', Request::create('/board')), 'nonLocking'];
        yield 'GET on a route marked for other frames' => [self::marked(['board-frame', 'board-count'], Request::create('/board'), 'other-frame'), 'nonLocking'];
        yield 'POST that claims to be a prefetch' => [self::prefetch(Request::create('/board', Request::METHOD_POST)), 'locking'];
    }

    #[DataProvider('requests')]
    public function test_it_picks_the_inner_handler_from_the_main_request(?Request $request, string $expected): void
    {
        $locking = $this->inner();
        $nonLocking = $this->inner();
        $chosen = 'locking' === $expected ? $locking : $nonLocking;
        $other = 'locking' === $expected ? $nonLocking : $locking;

        $chosen->expects($this->once())->method('open')->willReturn(true);
        $chosen->expects($this->once())->method('read')->with('sid')->willReturn('data');
        $chosen->expects($this->once())->method('write')->with('sid', 'new')->willReturn(true);
        $chosen->expects($this->once())->method('close')->willReturn(true);
        $other->expects($this->never())->method($this->anything());

        $handler = $this->handler($locking, $nonLocking, $request);

        self::assertTrue($handler->open('', 'PHPSESSID'));
        self::assertSame('data', $handler->read('sid'));
        self::assertTrue($handler->write('sid', 'new'));
        self::assertTrue($handler->close());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function readOnlyRequests(): iterable
    {
        yield 'route marked read-only' => [self::marked(true, Request::create('/count'))];
        yield 'POST on a route marked read-only' => [self::marked(true, Request::create('/count', Request::METHOD_POST))];
        yield 'frame route with its frame header' => [self::marked('board-frame', Request::create('/board'), 'board-frame')];
        yield 'route marked for several frames, with one of them' => [self::marked(['board-frame', 'board-count'], Request::create('/board'), 'board-count')];
        yield 'Turbo prefetch of an unmarked route' => [self::prefetch(Request::create('/board'))];
        yield 'browser prefetch of an unmarked route' => [self::prefetch(Request::create('/board'), 'Sec-Purpose')];
    }

    #[DataProvider('readOnlyRequests')]
    public function test_a_read_only_request_reads_without_a_lock_and_writes_nothing(Request $request): void
    {
        $locking = $this->inner();
        $nonLocking = $this->inner();

        $locking->expects($this->never())->method($this->anything());
        $nonLocking->expects($this->once())->method('open')->willReturn(true);
        $nonLocking->expects($this->once())->method('validateId')->with('sid')->willReturn(true);
        $nonLocking->expects($this->once())->method('read')->with('sid')->willReturn('data');
        $nonLocking->expects($this->once())->method('close')->willReturn(true);
        $nonLocking->expects($this->never())->method('write');
        $nonLocking->expects($this->never())->method('updateTimestamp');
        $nonLocking->expects($this->never())->method('destroy');
        $nonLocking->expects($this->never())->method('gc');

        $handler = $this->handler($locking, $nonLocking, $request);

        self::assertTrue($handler->open('', 'PHPSESSID'));
        self::assertTrue($handler->validateId('sid'));
        self::assertSame('data', $handler->read('sid'));
        self::assertTrue($handler->write('sid', 'new'));
        self::assertTrue($handler->updateTimestamp('sid', 'new'));
        self::assertTrue($handler->destroy('sid'));
        self::assertSame(0, $handler->gc(1440));
        self::assertTrue($handler->close());
    }

    public function test_it_picks_the_mode_again_on_each_open(): void
    {
        $locking = $this->inner();
        $nonLocking = $this->inner();
        $requests = new RequestStack();

        $locking->method('open')->willReturn(true);
        $locking->expects($this->once())->method('write')->willReturn(true);
        $nonLocking->method('open')->willReturn(true);
        $nonLocking->expects($this->never())->method('write');

        $handler = new ReadOnlyAwareSessionHandler($locking, $nonLocking, $requests, new RequestTimeline($this->createStub(HubInterface::class)));

        $requests->push(self::marked(true, Request::create('/count')));
        $handler->open('', 'PHPSESSID');
        $handler->write('sid', 'ignored');
        $requests->pop();

        $requests->push(Request::create('/board', Request::METHOD_POST));
        $handler->open('', 'PHPSESSID');
        $handler->write('sid', 'kept');
    }

    public function test_it_traces_each_read_with_the_lock_it_took(): void
    {
        $transaction = new Transaction(TransactionContext::make());
        $transaction->initSpanRecorder();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('getSpan')->willReturn($transaction);

        $locking = self::stubInner();
        $locking->method('open')->willReturn(true);
        $locking->method('validateId')->willReturn(true);
        $locking->method('read')->willReturn('data');

        $requests = new RequestStack();
        $requests->push(Request::create('/board', Request::METHOD_POST));
        $handler = new ReadOnlyAwareSessionHandler($locking, self::stubInner(), $requests, new RequestTimeline($hub));

        $handler->open('', 'PHPSESSID');
        $handler->validateId('sid');
        $handler->read('sid');

        $reads = array_values(array_filter(
            $transaction->getSpanRecorder()?->getSpans() ?? [],
            static fn ($span): bool => 'session.read' === $span->getOp(),
        ));

        self::assertCount(2, $reads);

        foreach ($reads as $read) {
            self::assertNotNull($read->getEndTimestamp());
            self::assertSame(['session.locked' => true, 'session.mode' => 'locking'], $read->getData());
        }
    }

    public function test_it_reads_with_no_active_span(): void
    {
        $locking = self::stubInner();
        $locking->method('open')->willReturn(true);
        $locking->method('read')->willReturn('data');

        $handler = $this->handler($locking, self::stubInner(), null);

        $handler->open('', 'PHPSESSID');

        self::assertSame('data', $handler->read('sid'));
    }

    public function test_it_times_every_open_as_the_session_connect(): void
    {
        $locking = self::stubInner();
        $locking->method('open')->willReturn(true);
        $locking->method('close')->willReturn(true);
        $requests = new RequestStack();
        $requests->push(Request::create('/login', Request::METHOD_POST));
        $timeline = new RequestTimeline($this->createStub(HubInterface::class));
        $handler = new ReadOnlyAwareSessionHandler($locking, self::stubInner(), $requests, $timeline);

        $handler->open('', 'PHPSESSID');
        $handler->close();
        $handler->open('', 'PHPSESSID');

        $connects = array_values(array_filter(
            $timeline->drain(),
            static fn (RecordedSpan $span): bool => 'session.connect' === $span->op,
        ));
        self::assertCount(2, $connects);
        self::assertSame(['session.mode' => 'locking'], $connects[0]->data);
        self::assertSame(['session.mode' => 'locking'], $connects[1]->data);
    }

    public function test_with_no_hub_span_it_records_each_read_with_the_lock_it_took(): void
    {
        $nonLocking = self::stubInner();
        $nonLocking->method('open')->willReturn(true);
        $nonLocking->method('read')->willReturn('data');
        $requests = new RequestStack();
        $requests->push(Request::create('/board'));
        $timeline = new RequestTimeline($this->createStub(HubInterface::class));
        $handler = new ReadOnlyAwareSessionHandler(self::stubInner(), $nonLocking, $requests, $timeline);

        $handler->open('', 'PHPSESSID');
        self::assertSame('data', $handler->read('sid'));

        $reads = array_values(array_filter(
            $timeline->drain(),
            static fn (RecordedSpan $span): bool => 'session.read' === $span->op,
        ));
        self::assertCount(1, $reads);
        self::assertSame(['session.locked' => false, 'session.mode' => 'non-locking'], $reads[0]->data);
    }

    /** @param string|list<string>|bool $mark */
    private static function marked(string|array|bool $mark, Request $request, ?string $frame = null): Request
    {
        $request->attributes->set(ReadOnlyAwareSessionHandler::READ_ONLY, $mark);

        if (null !== $frame) {
            $request->headers->set('Turbo-Frame', $frame);
        }

        return $request;
    }

    private static function prefetch(Request $request, string $header = 'X-Sec-Purpose'): Request
    {
        $request->headers->set($header, 'prefetch');

        return $request;
    }

    /**
     * @return \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface&MockObject
     */
    private function inner(): MockObject
    {
        $inner = $this->createMockForIntersectionOfInterfaces([\SessionHandlerInterface::class, \SessionUpdateTimestampHandlerInterface::class]);
        self::assertInstanceOf(\SessionHandlerInterface::class, $inner);
        self::assertInstanceOf(\SessionUpdateTimestampHandlerInterface::class, $inner);

        return $inner;
    }

    /**
     * @return \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface&Stub
     */
    private static function stubInner(): Stub
    {
        $inner = self::createStubForIntersectionOfInterfaces([\SessionHandlerInterface::class, \SessionUpdateTimestampHandlerInterface::class]);
        self::assertInstanceOf(\SessionHandlerInterface::class, $inner);
        self::assertInstanceOf(\SessionUpdateTimestampHandlerInterface::class, $inner);

        return $inner;
    }

    private function handler(
        \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $locking,
        \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $nonLocking,
        ?Request $request,
    ): ReadOnlyAwareSessionHandler {
        $requests = new RequestStack();

        if (null !== $request) {
            $requests->push($request);
        }

        return new ReadOnlyAwareSessionHandler($locking, $nonLocking, $requests, new RequestTimeline($this->createStub(HubInterface::class)));
    }
}
