<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;

/** Reads one page of the items matching a full-text query, closed items included. */
final readonly class SearchInboxHandler
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public const string QUERY_BLANK = 'inbox.item.error.search_query_blank';

    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(SearchInboxCommand $command): SearchInboxView
    {
        $query = trim($command->query);
        if ('' === $query) {
            // An empty tsquery matches nothing and raises nothing, so a blank
            // query would read as an empty inbox rather than as a mistake.
            throw new DomainErrors(['query' => self::QUERY_BLANK]);
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX would overflow the offset to a float.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, $perPage));

        $paginator = $this->inboxItems->searchByProject($command->project, $query, $page, $perPage);
        $total = \count($paginator);
        /** @var list<InboxItem> $items */
        $items = array_values(iterator_to_array($paginator, false));

        return new SearchInboxView(
            items: $items,
            page: $page,
            perPage: $perPage,
            total: $total,
            hasMore: ($page - 1) * $perPage + \count($items) < $total,
        );
    }
}
