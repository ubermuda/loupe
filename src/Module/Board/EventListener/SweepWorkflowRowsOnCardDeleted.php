<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Workflow\Contract\WorkflowRowCleanup;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** An evaluation that began before the card delete can write a rule state after it, so this deletes the rows again once the delete committed. */
#[AsEventListener]
final readonly class SweepWorkflowRowsOnCardDeleted
{
    public function __construct(
        private WorkflowRowCleanup $cleanup,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::DELETED !== $event->change) {
            return;
        }

        $this->cleanup->sweepCard($event->cardId);
    }
}
