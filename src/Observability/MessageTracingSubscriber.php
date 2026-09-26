<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * The bundle's MessengerListener runs at priority 50 and flushes the client.
 * This one opens after it and finishes before it, so the transaction nests
 * inside the bundle's scope and is sent with that flush.
 */
final class MessageTracingSubscriber implements EventSubscriberInterface
{
    private const string SCHEDULER_TRANSPORT = 'scheduler_default';

    private ?Transaction $transaction = null;

    private ?object $message = null;

    private ?Span $previousSpan = null;

    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', 40],
            WorkerMessageHandledEvent::class => ['onHandled', 60],
            WorkerMessageFailedEvent::class => ['onFailed', 60],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        // A later listener can refuse the message, and then no handled or failed event comes.
        $this->restore();

        if (self::SCHEDULER_TRANSPORT === $event->getReceiverName()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        $context = TransactionContext::make()
            ->setOp('queue.process')
            ->setOrigin('auto.queue')
            ->setName($message::class)
            ->setSource(TransactionSource::task())
            ->setTags(['messenger.receiver_name' => $event->getReceiverName()]);

        $this->previousSpan = $this->hub->getSpan();
        $this->message = $message;
        $this->transaction = $this->hub->startTransaction($context);
        $this->hub->setSpan($this->transaction);
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->finish($event, SpanStatus::ok());
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->finish($event, SpanStatus::internalError());
    }

    private function finish(AbstractWorkerMessageEvent $event, SpanStatus $status): void
    {
        if (null === $this->transaction || $event->getEnvelope()->getMessage() !== $this->message) {
            return;
        }

        $this->transaction->setStatus($status);
        $this->transaction->finish();
        $this->restore();
    }

    private function restore(): void
    {
        if (null === $this->transaction) {
            return;
        }

        $this->hub->setSpan($this->previousSpan);
        $this->transaction = null;
        $this->message = null;
        $this->previousSpan = null;
    }
}
