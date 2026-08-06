<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentListItem;

final readonly class ListDocumentsView
{
    /**
     * @param list<DocumentListItem> $items
     * @param list<int|null>         $pageList
     * @param list<Tag>              $projectTags
     */
    public function __construct(
        public array $items,
        public int $totalPages,
        public array $pageList,
        public array $projectTags,
        public ?int $clampedPage,
    ) {
    }
}
