<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;

/** The project inbox page: open asks, open items outside them, and one page of closed asks, or one page of search results. */
final readonly class InboxDetailView
{
    /** Where an item shows when no ask on the page holds it. */
    private const string LOOSE = 'loose';

    /** @var array<string, string> item id to the place its forms render */
    private array $homes;

    /**
     * @param list<InboxAsk>      $openAsks      oldest first
     * @param list<InboxItem>     $looseItems    open items that no open ask holds
     * @param list<InboxAsk>      $closedAsks    newest close first
     * @param list<int|null>      $pageList      page numbers of the closed asks or the search results, null for a gap
     * @param array<string, true> $finalItemIds  items a closed ask holds
     * @param list<InboxItem>     $searchResults best match first, when $query is not blank
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
        public string $query = '',
        public array $searchResults = [],
        public int $searchTotal = 0,
    ) {
        // An item can sit in several asks, and a to-do in a closed ask also shows
        // on its own. Its forms render once, at the first place the page shows it.
        $homes = [];
        foreach ($openAsks as $ask) {
            foreach ($ask->items as $link) {
                $homes[(string) $link->item->id] ??= (string) $ask->id;
            }
        }
        foreach ([...$looseItems, ...$searchResults] as $item) {
            $homes[(string) $item->id] ??= self::LOOSE;
        }
        foreach ($closedAsks as $ask) {
            foreach ($ask->items as $link) {
                $homes[(string) $link->item->id] ??= (string) $ask->id;
            }
        }
        $this->homes = $homes;
    }

    /** Whether the item's forms render here, in $ask, or on its own when $ask is null. */
    public function isHome(InboxItem $item, ?InboxAsk $ask = null): bool
    {
        return ($this->homes[(string) $item->id] ?? null) === (null === $ask ? self::LOOSE : (string) $ask->id);
    }

    /** Whether the owner can respond to the item, or change the response already given. */
    public function acceptsResponse(InboxItem $item): bool
    {
        return match ($item->state) {
            InboxItemState::Open => true,
            InboxItemState::Withdrawn, InboxItemState::Obsolete => false,
            InboxItemState::Answered, InboxItemState::Done, InboxItemState::Declined => !isset($this->finalItemIds[(string) $item->id]),
        };
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
