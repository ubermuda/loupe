<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnBoardColumnTerminalChanged
{
    public function __construct(
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(BoardColumnTerminalChanged $event): void
    {
        $this->trigger->forCards($event->cardIds);
    }
}
