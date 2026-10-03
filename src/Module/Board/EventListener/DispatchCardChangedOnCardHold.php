<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Event\CardHeld;
use App\Module\Bridge\Event\CardHoldsReleased;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A hold or a release changes the unmanaged marker on the tile of a card. Both run inside a transaction, so the change is an update. */
final readonly class DispatchCardChangedOnCardHold
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    #[AsEventListener]
    public function onCardHeld(CardHeld $event): void
    {
        $this->events->dispatch(new CardChanged($event->projectId, $event->cardId, CardChanged::UPDATED, false));
    }

    #[AsEventListener]
    public function onCardHoldsReleased(CardHoldsReleased $event): void
    {
        foreach ($event->cardIds as $cardId) {
            $this->events->dispatch(new CardChanged($event->projectId, $cardId, CardChanged::UPDATED, false));
        }
    }
}
