<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\View\InboxDetailView;
use App\Utils\PageList;

final readonly class ShowInboxHandler
{
    public const int CLOSED_ASKS_PER_PAGE = 10;

    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private BridgeLiveness $bridgeLiveness,
    ) {
    }

    public function __invoke(ShowInboxCommand $command): InboxDetailView
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

        return new InboxDetailView(
            project: $project,
            openAsks: $openAsks,
            looseItems: $looseItems,
            closedAsks: $closedAsks,
            closedAskTotal: $closedTotal,
            page: $page,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            finalItemIds: array_fill_keys($this->inboxAsks->findItemIdsHeldByClosedAsks(array_values($shown)), true),
            bridgeStatuses: $this->bridgeLiveness->forOwner($project->owner, array_values($bridgeIds)),
        );
    }
}
