<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\CardRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card that enters or leaves a terminal column opens or lifts the hold on the cards it blocks. */
#[AsEventListener]
final readonly class DispatchCardChangedOnBlockerMoved
{
    public function __construct(
        private CardRepository $cards,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        if ($event->move->fromColumn->terminal === $card->column->terminal) {
            return;
        }

        foreach ($this->cards->findBlockedBy($card) as $blocked) {
            $this->events->dispatch(new CardChanged(
                $blocked->project->id ?? throw new \LogicException('Project has no id.'),
                $blocked->id ?? throw new \LogicException('Card has no id.'),
                CardChanged::UPDATED,
                false,
            ));
        }
    }
}
