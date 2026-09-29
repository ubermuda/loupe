<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Inbox\Service\CardWaitTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A column delete moves its cards in bulk and dispatches no CardChanged for them. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnBoardColumnDeleted
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        $this->trigger->forCards($event->project->id ?? throw new \LogicException('Project has no id.'), $event->movedCardIds);
    }
}
