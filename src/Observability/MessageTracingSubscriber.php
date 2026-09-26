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
 * The bundle's MessengerListener runs at priority 50, captures a failure and
 * flushes the client. This one opens after it and finishes before it, so the
 * transaction nests inside the bundle's scope. On a failure the transaction
 * stays the hub span until after the capture, so the error shares its trace.
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
            WorkerMessageFailedEvent::class => [['onFailed', 60], ['restoreAfterFailure', 40]],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        // A later listener can refuse the message, and then no handled or failed event comes.
        // Finish the stale transaction unsampled, so its profiler stops and nothing is sent.
        $this->transaction?->setSampled(false);
        $this->transaction?->finish();
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
        if ($this->owns($event)) {
            $this->finish(SpanStatus::ok());
            $this->restore();
        }
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ($this->owns($event)) {
            $this->finish(SpanStatus::internalError());
        }
    }

    public function restoreAfterFailure(WorkerMessageFailedEvent $event): void
    {
        if ($this->owns($event)) {
            $this->restore();
        }
    }

    private function owns(AbstractWorkerMessageEvent $event): bool
    {
        return null !== $this->transaction && $event->getEnvelope()->getMessage() === $this->message;
    }

    private function finish(SpanStatus $status): void
    {
        $this->transaction?->setStatus($status);
        $this->transaction?->finish();
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
