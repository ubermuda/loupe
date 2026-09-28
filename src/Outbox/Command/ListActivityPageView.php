<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntry;

final readonly class ListActivityPageView
{
    /**
     * @param list<ActivityEntry> $entries
     * @param list<int|null>      $pageList
     */
    public function __construct(
        public array $entries,
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        public ?int $clampedPage,
    ) {
    }
}
