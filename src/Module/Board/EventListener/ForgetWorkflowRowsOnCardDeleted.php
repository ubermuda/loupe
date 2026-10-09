<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardDeleted;
use App\Module\Workflow\Contract\WorkflowRowCleanup;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The workflow tables keep the card id without a foreign key, so they outlive the card until this deletes their rows. */
#[AsEventListener]
final readonly class ForgetWorkflowRowsOnCardDeleted
{
    public function __construct(
        private WorkflowRowCleanup $cleanup,
    ) {
    }

    public function __invoke(CardDeleted $event): void
    {
        $this->cleanup->forgetCard($event->cardId);
    }
}
