<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnWorkerRunChanged
{
    public function __construct(
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $this->trigger->forCards($event->cardIds);
    }
}
