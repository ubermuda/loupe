<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Audit\LoupeAuditActorProvider;
use App\Messenger\Stamp\AuditChannelStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Carries the dispatching channel across the queue, so an export a logged-in
 * person asked for is recorded against them rather than against the worker.
 *
 * The worker is recognised by ConsumedByWorkerStamp, not ReceivedStamp: the sync
 * transport adds the latter too, and handling a message inline inside a request
 * is not asynchronous.
 */
final readonly class AuditChannelMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuditContext $auditContext,
        private LoupeAuditActorProvider $actorProvider,
    ) {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null !== $envelope->last(ConsumedByWorkerStamp::class)) {
            return $this->handleInWorker($envelope, $stack);
        }

        if (null === $envelope->last(ReceivedStamp::class) && null === $envelope->last(AuditChannelStamp::class)) {
            $envelope = $envelope->with(new AuditChannelStamp($this->actorProvider->currentChannel()));
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function handleInWorker(Envelope $envelope, StackInterface $stack): Envelope
    {
        $channel = $this->auditContext->channel;
        $ambientContext = $this->auditContext->ambientContext;

        $stamp = $envelope->last(AuditChannelStamp::class);

        // No stamp means the message never went through the dispatch side above:
        // it came off the scheduler transport as a cron tick.
        $this->auditContext->channel = $stamp instanceof AuditChannelStamp ? $stamp->channel : AuditChannel::Cron;
        $this->auditContext->ambientContext = ['async' => true] + $ambientContext;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->auditContext->channel = $channel;
            $this->auditContext->ambientContext = $ambientContext;
        }
    }
}
