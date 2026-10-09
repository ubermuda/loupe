<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A column delete moves its cards in bulk and dispatches no CardMoved for them. */
#[AsEventListener]
final readonly class EvaluateCardsOnBoardColumnDeleted
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        $this->trigger->forCards($event->movedCardIds);
    }
}
