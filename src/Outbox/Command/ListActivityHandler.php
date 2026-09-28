<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntryBuilder;
use App\Outbox\Repository\OutboxEventRepository;

final readonly class ListActivityHandler
{
    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private ActivityEntryBuilder $entries,
    ) {
    }

    public function __invoke(ListActivityCommand $command): ListActivityView
    {
        $events = $this->outboxEvents->findRecentForProject($command->project, $command->limit);

        return new ListActivityView($this->entries->entriesFor($command->project, $events));
    }
}
