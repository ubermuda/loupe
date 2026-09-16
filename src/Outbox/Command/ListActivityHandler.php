<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\Repository\OutboxEventRepository;

final readonly class ListActivityHandler
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
    ) {
    }

    public function __invoke(ListActivityCommand $command): ListActivityView
    {
        return new ListActivityView(
            $command->project,
            $this->outboxEvents->findRecentForProject($command->project),
        );
    }
}
