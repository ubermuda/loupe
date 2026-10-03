<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnWorkerRunChanged
{
    public function __construct(
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        $this->trigger->forCards($event->cardIds);
    }
}
