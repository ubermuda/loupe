<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Event\CardDocumentsChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A rule reads the documents of a card, and of its parent, so a link change can open or close a gate. */
#[AsEventListener]
final readonly class EvaluateCardOnCardDocumentsChanged
{
    public function __construct(
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(CardDocumentsChanged $event): void
    {
        $this->trigger->forCards([$event->cardId]);
    }
}
