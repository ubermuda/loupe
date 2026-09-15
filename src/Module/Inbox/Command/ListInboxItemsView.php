<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;

/** One page of items, with the clamped paging the handler used. */
final readonly class ListInboxItemsView
{
    /** @param list<InboxItem> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public bool $hasMore,
    ) {
    }
}
