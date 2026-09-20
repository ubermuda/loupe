<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Inbox\View\InboxDetailView;
use App\Module\Project\Entity\Project;
use App\Utils\PageList;

final readonly class ShowInboxHandler
{
    public const int CLOSED_ASKS_PER_PAGE = 10;

    public const int SEARCH_RESULTS_PER_PAGE = 25;

    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private BridgeLiveness $bridgeLiveness,
        private SearchInboxHandler $searchInbox,
        private InboxReviewLookup $inboxReviews,
    ) {
    }

    public function __invoke(ShowInboxCommand $command): InboxDetailView
    {
        $query = trim($command->query);

        return '' === $query ? $this->asks($command) : $this->search($command->project, $query, $command->page);
    }

    private function asks(ShowInboxCommand $command): InboxDetailView
    {
        $project = $command->project;
        $closedTotal = $this->inboxAsks->countClosedForProject($project);
        $totalPages = max(1, (int) ceil($closedTotal / self::CLOSED_ASKS_PER_PAGE));
        $page = min(max(1, $command->page), $totalPages);

        $openAsks = $this->inboxAsks->findOpenForProject($project);
        $looseItems = $this->inboxItems->findOpenOutsideOpenAsks($project);
        $closedAsks = $this->inboxAsks->findClosedPageForProject($project, ($page - 1) * self::CLOSED_ASKS_PER_PAGE, self::CLOSED_ASKS_PER_PAGE);

        $bridgeIds = [];
        foreach ($openAsks as $ask) {
            if (null !== $ask->bridgeId) {
                $bridgeIds[$ask->bridgeId->toRfc4122()] = $ask->bridgeId;
            }
        }

        $shown = [];
        foreach ($looseItems as $item) {
            $shown[(string) $item->id] = $item;
        }
        foreach ([...$openAsks, ...$closedAsks] as $ask) {
            foreach ($ask->items as $link) {
                $shown[(string) $link->item->id] = $link->item;
            }
        }

        $this->inboxReviews->preload(array_values($shown));

        return new InboxDetailView(
            project: $project,
            openAsks: $openAsks,
            looseItems: $looseItems,
            closedAsks: $closedAsks,
            closedAskTotal: $closedTotal,
            page: $page,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            finalItemIds: $this->finalItemIds(array_values($shown)),
            bridgeStatuses: $this->bridgeLiveness->forOwner($project->owner, array_values($bridgeIds)),
            completed: $command->completed,
        );
    }

    private function search(Project $project, string $query, int $page): InboxDetailView
    {
        $results = ($this->searchInbox)(new SearchInboxCommand($project, $query, $page, self::SEARCH_RESULTS_PER_PAGE));
        $totalPages = max(1, (int) ceil($results->total / self::SEARCH_RESULTS_PER_PAGE));
        // A page past the end, such as a bookmark after items went, shows the last one.
        if ($results->page > $totalPages) {
            $results = ($this->searchInbox)(new SearchInboxCommand($project, $query, $totalPages, self::SEARCH_RESULTS_PER_PAGE));
        }

        $this->inboxReviews->preload($results->items);

        return new InboxDetailView(
            project: $project,
            openAsks: [],
            looseItems: [],
            closedAsks: [],
            closedAskTotal: 0,
            page: $results->page,
            totalPages: $totalPages,
            pageList: PageList::build($results->page, $totalPages),
            finalItemIds: $this->finalItemIds($results->items),
            bridgeStatuses: [],
            query: $query,
            searchResults: $results->items,
            searchTotal: $results->total,
        );
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return array<string, true>
     */
    private function finalItemIds(array $items): array
    {
        return array_fill_keys($this->inboxAsks->findItemIdsHeldByClosedAsks($items), true);
    }
}
