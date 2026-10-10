<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardBlockersChanged;
use App\Module\Board\Event\CardChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card that gains or loses a blocker may start or stop waiting, so its tile redraws. */
#[AsEventListener]
final readonly class DispatchCardChangedOnCardBlockersChanged
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(CardBlockersChanged $event): void
    {
        foreach ($event->cards as $card) {
            $this->events->dispatch(new CardChanged(
                $event->project->id ?? throw new \LogicException('Project has no id.'),
                $card->id ?? throw new \LogicException('Card has no id.'),
                CardChanged::UPDATED,
                false,
            ));
        }
    }
}
