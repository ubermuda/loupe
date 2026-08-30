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
    /** The transport the scheduler delivers its ticks on. */
    private const string SCHEDULER_TRANSPORT = 'scheduler_default';

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

        $this->auditContext->channel = $this->channelOf($envelope);
        $this->auditContext->ambientContext = ['async' => true] + $ambientContext;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->auditContext->channel = $channel;
            $this->auditContext->ambientContext = $ambientContext;
        }
    }

    private function channelOf(Envelope $envelope): AuditChannel
    {
        $stamp = $envelope->last(AuditChannelStamp::class);
        if ($stamp instanceof AuditChannelStamp) {
            return $stamp->channel;
        }

        // An unstamped message never went through the dispatch side, and only
        // the scheduler transport says why: everything else arriving unstamped —
        // queued before this shipped, replayed from `failed`, sent straight to a
        // transport — has provenance nothing here can recover.
        $received = $envelope->last(ReceivedStamp::class);

        return $received instanceof ReceivedStamp && self::SCHEDULER_TRANSPORT === $received->getTransportName()
            ? AuditChannel::Cron
            : AuditChannel::System;
    }
}
