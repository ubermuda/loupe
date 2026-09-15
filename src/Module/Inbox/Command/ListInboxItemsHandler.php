<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;

/** Reads one page of a project's items, newest first, narrowed by the filters given. */
final readonly class ListInboxItemsHandler
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(ListInboxItemsCommand $command): ListInboxItemsView
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX would overflow the offset to a float.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, $perPage));

        $paginator = $this->inboxItems->findPageForProject(
            $command->project,
            $command->state,
            $command->askId,
            $command->sessionId,
            $command->cardId,
            $command->documentId,
            $page,
            $perPage,
        );
        $total = \count($paginator);
        /** @var list<InboxItem> $items */
        $items = array_values(iterator_to_array($paginator, false));

        return new ListInboxItemsView(
            items: $items,
            page: $page,
            perPage: $perPage,
            total: $total,
            hasMore: ($page - 1) * $perPage + \count($items) < $total,
        );
    }
}
