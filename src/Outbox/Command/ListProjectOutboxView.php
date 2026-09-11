<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;

final readonly class ListProjectOutboxView
{
    /** @param list<OutboxEvent> $events */
    public function __construct(
        public Project $project,
        public array $events,
    ) {
    }
}
