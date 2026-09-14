<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\View\WorkerRunListItem;
use Symfony\Component\Uid\Uuid;

final readonly class ListWorkerRunsView
{
    /**
     * @param list<WorkerRunListItem> $items
     * @param list<int|null>          $pageList
     * @param list<Uuid>              $bridgeIds
     */
    public function __construct(
        public array $items,
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        public array $bridgeIds,
        public ?int $clampedPage,
    ) {
    }
}
