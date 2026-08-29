<?php

declare(strict_types=1);

namespace App\Audit\EventListener;

use App\Module\Audit\Auditor;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Drains the buffering sinks at the end of every unit of work this application
 * has. All four are needed: each is the only end-of-work signal in its own
 * context, and a missing one loses records there and nowhere else.
 */
#[AsEventListener(event: TerminateEvent::class)]
#[AsEventListener(event: ConsoleTerminateEvent::class)]
#[AsEventListener(event: WorkerMessageHandledEvent::class)]
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class FlushAuditSinksListener
{
    public function __construct(
        private Auditor $auditor,
    ) {
    }

    public function __invoke(): void
    {
        $this->auditor->flush();
    }
}
