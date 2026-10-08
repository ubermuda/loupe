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
     * @param array<string, string>   $bridgeLabels RFC 4122 bridge id => its label
     * @param list<string>            $harnesses
     * @param list<string>            $accounts
     * @param list<string>            $models
     */
    public function __construct(
        public array $items,
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        public array $bridgeIds,
        public ?int $clampedPage,
        public int $page,
        public int $perPage,
        public array $bridgeLabels = [],
        public array $harnesses = [],
        public array $accounts = [],
        public array $models = [],
    ) {
    }
}
