<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardParentChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnCardParentChanged
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardParentChanged $event): void
    {
        $this->trigger->forCards(array_values(array_filter(
            [$event->card->id, $event->oldParent?->id, $event->newParent?->id],
            static fn ($id): bool => null !== $id,
        )));
    }
}
