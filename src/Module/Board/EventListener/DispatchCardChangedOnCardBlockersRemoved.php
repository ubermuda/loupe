<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardBlockersRemoved;
use App\Module\Board\Event\CardChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card that loses a blocker may stop waiting, so its tile redraws. */
#[AsEventListener]
final readonly class DispatchCardChangedOnCardBlockersRemoved
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(CardBlockersRemoved $event): void
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
