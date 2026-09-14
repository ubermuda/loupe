<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads one page of a project's items, newest first, narrowed by the filters
 * given. A reader session records its read on the items the page returns.
 */
final readonly class ListInboxItemsHandler
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxAskRepository $inboxAsks,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ListInboxItemsCommand $command): ListInboxItemsView
    {
        $readerSessionId = $command->readerSessionId;
        if (null === $readerSessionId) {
            return $this->page($command);
        }

        // The page and the stamp share the lock the ask closer takes, so an ask
        // cannot close between a read of the old answer and the stamp.
        return $this->em->wrapInTransaction(function () use ($command, $readerSessionId): ListInboxItemsView {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);
            $view = $this->page($command);
            // An item managed since an earlier call keeps its old copy through the query.
            $this->inboxItems->reloadReadableColumns($view->items);
            $this->inboxAsks->recordRead($readerSessionId, $view->items, new \DateTimeImmutable());

            return $view;
        });
    }

    private function page(ListInboxItemsCommand $command): ListInboxItemsView
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
