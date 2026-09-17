<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Outbox\Entity\OutboxEvent;

final readonly class ActivityEntry
{
    public function __construct(
        public OutboxEvent $event,
        public ?ActivityLink $link,
    ) {
    }
}
