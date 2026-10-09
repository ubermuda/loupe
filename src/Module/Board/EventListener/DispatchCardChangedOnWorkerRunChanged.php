<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Event\WorkerRunChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** A run that opens or closes moves its card between Working and another state. */
#[AsEventListener]
final readonly class DispatchCardChangedOnWorkerRunChanged
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        foreach ($event->cardIds as $cardId) {
            $this->events->dispatch(new CardChanged($event->projectId, Uuid::fromString($cardId), CardChanged::UPDATED, false));
        }
    }
}
