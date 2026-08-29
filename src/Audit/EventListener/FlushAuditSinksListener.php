<?php

declare(strict_types=1);

namespace App\Audit\EventListener;

use App\Module\Audit\Auditor;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Drains the buffering sinks at the end of every unit of work this application
 * has. Each event is the only end-of-work signal in some context, so a missing
 * one loses records there and nowhere else: WorkerRunningEvent is the only one
 * a message declined by a WorkerMessageReceivedEvent listener reaches, because
 * Worker::handleMessage() then returns without a handled or failed event.
 */
#[AsEventListener(event: TerminateEvent::class, priority: self::BEFORE_SERVICES_RESET)]
#[AsEventListener(event: ConsoleTerminateEvent::class, priority: self::BEFORE_SERVICES_RESET)]
#[AsEventListener(event: WorkerMessageHandledEvent::class, priority: self::BEFORE_SERVICES_RESET)]
#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: self::BEFORE_SERVICES_RESET)]
#[AsEventListener(event: WorkerRunningEvent::class, priority: self::BEFORE_SERVICES_RESET)]
#[AsEventListener(event: WorkerStoppedEvent::class, priority: self::BEFORE_SERVICES_RESET)]
final readonly class FlushAuditSinksListener
{
    /**
     * Lower priority is later, and the drain has a deadline on each side: after
     * everything that might still record, but before Messenger's
     * ResetServicesListener, which subscribes to WorkerRunning and WorkerStopped
     * at -1024 and empties the sink through the services resetter. -1023 is the
     * only value clearing both. messenger:consume adds that listener at runtime,
     * so debug:event-dispatcher never shows it.
     */
    public const int BEFORE_SERVICES_RESET = -1023;

    public function __construct(
        private Auditor $auditor,
    ) {
    }

    public function __invoke(): void
    {
        $this->auditor->flush();
    }
}
