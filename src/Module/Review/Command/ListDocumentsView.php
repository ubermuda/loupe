<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentListItem;

final readonly class ListDocumentsView
{
    /**
     * @param list<DocumentListItem> $items
     * @param list<int|null>         $pageList
     * @param list<Tag>              $projectTags
     * @param list<Series>           $projectSeries
     */
    public function __construct(
        public array $items,
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        public array $projectTags,
        public array $projectSeries,
        public ?int $clampedPage,
    ) {
    }
}
