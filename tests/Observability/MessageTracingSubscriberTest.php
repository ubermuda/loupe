<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\MessageTracingSubscriber;
use App\Observability\TracesSampler;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final class MessageTracingSubscriberTest extends TestCase
{
    /** @var \ArrayObject<int, Event> */
    private \ArrayObject $sent;

    private TransportInterface $transport;

    private Hub $hub;

    private MessageTracingSubscriber $subscriber;

    #[\Override]
    protected function setUp(): void
    {
        $this->sent = new \ArrayObject();
        $this->transport = new readonly class($this->sent) implements TransportInterface {
            /** @param \ArrayObject<int, Event> $sent */
            public function __construct(
                private \ArrayObject $sent,
            ) {
            }

            #[\Override]
            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            #[\Override]
            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        $this->hub = $this->hub('https://key@o0.ingest.example/1');
        $this->subscriber = new MessageTracingSubscriber($this->hub);
    }

    public function test_a_received_message_starts_a_queue_transaction_named_after_its_class(): void
    {
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));

        $span = $this->hub->getSpan();
        self::assertInstanceOf(Transaction::class, $span);
        self::assertSame('queue.process', $span->getOp());
        self::assertSame(\stdClass::class, $span->getName());
        self::assertSame(['messenger.receiver_name' => 'async'], $span->getTags());
        self::assertTrue($span->getSampled());
    }

    public function test_a_handled_message_sends_an_ok_transaction_and_restores_the_previous_span(): void
    {
        $previous = new Span();
        $this->hub->setSpan($previous);
        $envelope = new Envelope(new \stdClass());

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $sent = $this->onlySent();
        $trace = $sent->getContexts()['trace'] ?? [];
        self::assertSame(\stdClass::class, $sent->getTransaction());
        self::assertSame('queue.process', $trace['op'] ?? null);
        self::assertSame('ok', $trace['status'] ?? null);
        self::assertSame($previous, $this->hub->getSpan());
    }

    public function test_a_failed_message_sends_an_internal_error_transaction(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $failed = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom'));
        $this->subscriber->onFailed($failed);

        self::assertSame('internal_error', $this->onlySent()->getContexts()['trace']['status'] ?? null);
        self::assertInstanceOf(Transaction::class, $this->hub->getSpan());

        $this->subscriber->restoreAfterFailure($failed);
        self::assertNull($this->hub->getSpan());
    }

    public function test_the_error_of_a_failed_message_shares_the_trace_of_its_transaction(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->subscriber);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, [new MessengerListener($this->hub), 'handleWorkerMessageFailedEvent'], 50);
        $envelope = new Envelope(new \stdClass());

        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom')));

        $byType = [];
        foreach ($this->sent as $event) {
            $byType[(string) $event->getType()] = $event->getContexts()['trace']['trace_id'] ?? null;
        }
        self::assertCount(2, $byType);
        self::assertNotNull($byType['transaction'] ?? null);
        self::assertSame($byType['transaction'], $byType['event'] ?? null);
        self::assertNull($this->hub->getSpan());
    }

    public function test_a_scheduler_message_starts_no_transaction(): void
    {
        $scheduled = new Envelope(new \stdClass());
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($scheduled, 'scheduler_default'));

        self::assertNull($this->hub->getSpan());
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($scheduled, 'scheduler_default'));
        self::assertCount(0, $this->sent);

        $queued = new Envelope(new \stdClass());
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($queued, 'async'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent($queued, 'async'));
        self::assertCount(1, $this->sent);
    }

    public function test_a_handled_event_for_another_message_finishes_nothing(): void
    {
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));
        $this->subscriber->onHandled(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'async'));

        self::assertCount(0, $this->sent);
        self::assertInstanceOf(Transaction::class, $this->hub->getSpan());
    }

    public function test_a_message_that_is_never_handled_is_dropped_at_the_next_receive(): void
    {
        $previous = new Span();
        $this->hub->setSpan($previous);
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));
        $skipped = $this->hub->getSpan();
        self::assertInstanceOf(Transaction::class, $skipped);

        $next = new Envelope(new \ArrayObject());
        $this->subscriber->onReceived(new WorkerMessageReceivedEvent($next, 'async'));
        $current = $this->hub->getSpan();
        self::assertInstanceOf(Transaction::class, $current);
        self::assertNotSame($skipped, $current);
        self::assertNull($current->getParentSpanId());
        self::assertNotNull($skipped->getEndTimestamp());
        self::assertFalse($skipped->getSampled());

        $this->subscriber->onHandled(new WorkerMessageHandledEvent($next, 'async'));
        self::assertSame(\ArrayObject::class, $this->onlySent()->getTransaction());
        self::assertSame($previous, $this->hub->getSpan());
    }

    public function test_without_a_dsn_the_transaction_is_unsampled_and_nothing_is_sent(): void
    {
        $hub = $this->hub(null);
        $subscriber = new MessageTracingSubscriber($hub);
        $envelope = new Envelope(new \stdClass());

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $span = $hub->getSpan();
        self::assertInstanceOf(Transaction::class, $span);
        self::assertFalse($span->getSampled());

        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertCount(0, $this->sent);
        self::assertNull($hub->getSpan());
    }

    private function onlySent(): Event
    {
        self::assertCount(1, $this->sent);
        $event = $this->sent[0];
        self::assertInstanceOf(Event::class, $event);

        return $event;
    }

    private function hub(?string $dsn): Hub
    {
        $client = ClientBuilder::create([
            'dsn' => $dsn ?? 'https://key@o0.ingest.example/1',
            'traces_sampler' => new TracesSampler($dsn, 1.0),
            'default_integrations' => false,
        ])->setTransport($this->transport)->getClient();

        return new Hub($client);
    }
}
