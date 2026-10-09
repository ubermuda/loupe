<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardDocumentsChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A rule reads the documents of a card, and of its parent, so a link change can open or close a gate. */
#[AsEventListener]
final readonly class EvaluateCardOnCardDocumentsChanged
{
    public function __construct(
        private CardRepository $cards,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardDocumentsChanged $event): void
    {
        $this->trigger->forCards([$event->cardId, ...$this->cards->findChildIds($event->cardId)]);
    }
}
