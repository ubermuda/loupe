<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\View\BridgeStatus;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;

/** The project inbox page: open asks, open items outside them, and one page of closed asks, or one page of search results. */
final readonly class InboxDetailView implements InboxItemsView
{
    /** Where an item shows when no ask on the page holds it. */
    private const string LOOSE = 'loose';

    /** @var array<string, string> item id to the place its forms render */
    private array $homes;

    public int $openItemCount;

    /**
     * @param list<InboxAsk>              $openAsks       oldest first
     * @param list<InboxItem>             $looseItems     open items that no open ask holds
     * @param list<InboxAsk>              $closedAsks     newest close first
     * @param list<int|null>              $pageList       page numbers of the closed asks or the search results, null for a gap
     * @param array<string, true>         $finalItemIds   items a closed ask holds
     * @param array<string, BridgeStatus> $bridgeStatuses the bridges of the open asks, by bridge id
     * @param list<InboxItem>             $searchResults  best match first, when $query is not blank
     */
    public function __construct(
        public Project $project,
        public array $openAsks,
        public array $looseItems,
        public array $closedAsks,
        public int $closedAskTotal,
        public int $page,
        public int $totalPages,
        public array $pageList,
        public array $finalItemIds,
        public array $bridgeStatuses,
        public string $query = '',
        public array $searchResults = [],
        public int $searchTotal = 0,
    ) {
        // An item can sit in several asks, and a to-do in a closed ask also shows
        // on its own. Its forms render once, at the first place the page shows it.
        $homes = [];
        $openItems = [];
        foreach ($openAsks as $ask) {
            foreach ($ask->items as $link) {
                $homes[(string) $link->item->id] ??= (string) $ask->id;
                if (InboxItemState::Open === $link->item->state) {
                    $openItems[(string) $link->item->id] = true;
                }
            }
        }
        foreach ($looseItems as $item) {
            $homes[(string) $item->id] ??= self::LOOSE;
            $openItems[(string) $item->id] = true;
        }

        $this->openItemCount = count($openItems);

        foreach ($searchResults as $item) {
            $homes[(string) $item->id] ??= self::LOOSE;
        }

        foreach ($closedAsks as $ask) {
            foreach ($ask->items as $link) {
                $homes[(string) $link->item->id] ??= (string) $ask->id;
            }
        }
        $this->homes = $homes;
    }

    #[\Override]
    public function isHome(InboxItem $item, ?InboxAsk $ask = null): bool
    {
        return ($this->homes[(string) $item->id] ?? null) === (null === $ask ? self::LOOSE : (string) $ask->id);
    }

    #[\Override]
    public function acceptsResponse(InboxItem $item): bool
    {
        if (InboxItemKind::Review === $item->kind && InboxItemState::Open !== $item->state) {
            return false;
        }

        return $item->state->acceptsResponse(isset($this->finalItemIds[(string) $item->id]));
    }

    /** The page and the search the owner is on, kept on the way back. */
    #[\Override]
    public function actionQuery(): array
    {
        return [...($this->page > 1 ? ['page' => $this->page] : []), ...($this->isSearch() ? ['q' => $this->query] : [])];
    }

    /** Null for a closed ask, or for an interactive session that no bridge resumes. */
    public function bridgeStatus(InboxAsk $ask): ?BridgeStatus
    {
        if (null !== $ask->closedAt || null === $ask->bridgeId) {
            return null;
        }

        return $this->bridgeStatuses[$ask->bridgeId->toRfc4122()]
            ?? throw new \LogicException('The inbox handler reads the bridge of every open ask on the page.');
    }

    public function isSearch(): bool
    {
        return '' !== $this->query;
    }

    public function isEmpty(): bool
    {
        return !$this->isSearch() && [] === $this->openAsks && [] === $this->looseItems && 0 === $this->closedAskTotal;
    }
}
