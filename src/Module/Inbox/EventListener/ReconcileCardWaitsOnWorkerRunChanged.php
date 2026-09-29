<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Inbox\Service\CardWaitTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The newest run of a card can start a wait or end one. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnWorkerRunChanged
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $this->trigger->forCards($event->projectId, $event->cardIds);
    }
}
