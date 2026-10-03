<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\ResumableWorkerRunEnded;
use App\Module\Bridge\Messenger\ResumeAskingSession;
use App\Module\Inbox\Repository\InboxAskRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An ask that closed while its run still ran got no resume, because a running
 * run takes none. The end of the run queues that resume.
 */
#[AsEventListener]
final readonly class ResumeAskingSessionOnResumableWorkerRunEnded
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ResumableWorkerRunEnded $event): void
    {
        $closedAt = $this->inboxAsks->findLatestBlockingCloseOfSessionAfter($event->projectId, $event->sessionId, $event->bridgeId, $event->startedAt);
        if (null === $closedAt) {
            return;
        }

        $this->bus->dispatch(new ResumeAskingSession((string) $event->projectId, (string) $event->bridgeId, (string) $event->sessionId, $closedAt));
    }
}
