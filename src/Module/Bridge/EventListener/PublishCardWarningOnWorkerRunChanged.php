<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** The newest run of a card decides its warning, so any change to a run of the card can raise or hide it. */
#[AsEventListener]
final readonly class PublishCardWarningOnWorkerRunChanged
{
    public function __construct(
        private WorkerRunChangedPublisher $publisher,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        foreach ($event->cardIds as $cardId) {
            $this->publisher->cardWarningChanged($event->projectId, Uuid::fromString($cardId));
        }
    }
}
