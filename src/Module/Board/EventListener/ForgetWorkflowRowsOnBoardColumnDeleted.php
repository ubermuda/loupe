<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Workflow\Contract\WorkflowRowCleanup;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** A slot link keeps the column id without a foreign key, so this clears it and the slot shows as unlinked. */
#[AsEventListener]
final readonly class ForgetWorkflowRowsOnBoardColumnDeleted
{
    public function __construct(
        private WorkflowRowCleanup $cleanup,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        $this->cleanup->forgetColumn(Uuid::fromString($event->columnId));
    }
}
