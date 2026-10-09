<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnBoardColumnTerminalChanged
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        $this->trigger->forCards($event->cardIds);
    }
}
