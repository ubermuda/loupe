<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntry;

final readonly class ListActivityView
{
    /** @param list<ActivityEntry> $entries */
    public function __construct(
        public array $entries,
    ) {
    }
}
