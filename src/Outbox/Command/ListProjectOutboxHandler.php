<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\Repository\OutboxEventRepository;

final readonly class ListProjectOutboxHandler
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
    ) {
    }

    public function __invoke(ListProjectOutboxCommand $command): ListProjectOutboxView
    {
        return new ListProjectOutboxView(
            project: $command->project,
            events: $this->outboxEvents->findUnsentForProject($command->project),
        );
    }
}
