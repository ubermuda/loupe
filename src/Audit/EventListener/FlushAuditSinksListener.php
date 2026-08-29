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

/**
 * Drains the buffering sinks at the end of every unit of work this application
 * has. Each event is the only end-of-work signal in some context, so a missing
 * one loses records there and nowhere else: WorkerRunningEvent is the only one
 * a message declined by a WorkerMessageReceivedEvent listener reaches, because
 * Worker::handleMessage() then returns without a handled or failed event.
 *
 * The priority is load-bearing, not decoration. A listener below the drain
 * records into a buffer that has already been emptied, and those records then
 * leak into the next message or die at the container reset — so the drain runs
 * after everything else, and normalising this to the default breaks it
 * silently.
 */
#[AsEventListener(event: TerminateEvent::class, priority: self::LAST)]
#[AsEventListener(event: ConsoleTerminateEvent::class, priority: self::LAST)]
#[AsEventListener(event: WorkerMessageHandledEvent::class, priority: self::LAST)]
#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: self::LAST)]
#[AsEventListener(event: WorkerRunningEvent::class, priority: self::LAST)]
final readonly class FlushAuditSinksListener
{
    /** Below every listener Symfony, its bundles or this application register on these events. */
    public const int LAST = -1024;

    public function __construct(
        private Auditor $auditor,
    ) {
    }

    public function __invoke(): void
    {
        $this->auditor->flush();
    }
}
