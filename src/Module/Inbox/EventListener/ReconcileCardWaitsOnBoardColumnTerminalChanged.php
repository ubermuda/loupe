<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Inbox\Service\CardWaitTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card in a terminal column waits for nothing, so the flag decides the waits of its cards. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnBoardColumnTerminalChanged
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        $this->trigger->forCards($event->project->id ?? throw new \LogicException('Project has no id.'), $event->cardIds);
    }
}
