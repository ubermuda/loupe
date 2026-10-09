<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A move changes the slot of the card, the children of its epic, and the blockers of the cards it blocks. */
#[AsEventListener]
final readonly class EvaluateCardsOnCardMoved
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        $this->trigger->forCards(array_values(array_filter(
            [$card->id, $card->parent?->id, ...array_map(static fn (Card $blocked) => $blocked->id, $this->cards->findBlockedBy($card))],
            static fn ($id): bool => null !== $id,
        )));
    }
}
