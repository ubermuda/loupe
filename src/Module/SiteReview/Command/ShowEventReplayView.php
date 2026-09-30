<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Outbox\Entity\OutboxEvent;

final readonly class ShowEventReplayView
{
    /** @param list<OutboxEvent> $events */
    public function __construct(
        public array $events,
        public bool $hasMore,
    ) {
    }
}
