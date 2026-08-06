<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\View\ProjectListItem;

final readonly class ListProjectsView
{
    /**
     * @param list<ProjectListItem> $items
     * @param list<int|null>        $pageList
     */
    public function __construct(
        public array $items,
        public int $totalPages,
        public array $pageList,
        public ?int $clampedPage,
    ) {
    }
}
