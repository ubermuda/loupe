<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardBlockersChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnCardBlockersChanged
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardBlockersChanged $event): void
    {
        $this->trigger->forCards(array_values(array_filter(
            array_map(static fn (Card $card) => $card->id, $event->cards),
            static fn ($id): bool => null !== $id,
        )));
    }
}
