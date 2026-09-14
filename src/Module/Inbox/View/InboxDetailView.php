<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;

/** The project inbox page: open asks, open items outside them, and one page of closed asks. */
final readonly class InboxDetailView implements InboxItemsView
{
    /** Where an item shows when no ask on the page holds it. */
    private const string LOOSE = 'loose';

    /** @var array<string, string> item id to the place its forms render */
    private array $homes;

    /**
     * @param list<InboxAsk>      $openAsks     oldest first
     * @param list<InboxItem>     $looseItems   open items that no open ask holds
     * @param list<InboxAsk>      $closedAsks   newest close first
     * @param list<int|null>      $pageList     page numbers of the closed asks, null for a gap
     * @param array<string, true> $finalItemIds items a closed ask holds
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
    ) {
        // An item can sit in several asks, and a to-do in a closed ask also shows
        // on its own. Its forms render once, at the first place the page shows it.
        $homes = [];
        foreach ($openAsks as $ask) {
            foreach ($ask->items as $link) {
                $homes[(string) $link->item->id] ??= (string) $ask->id;
            }
        }
        foreach ($looseItems as $item) {
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
        return $item->state->acceptsResponse(isset($this->finalItemIds[(string) $item->id]));
    }

    /** The closed asks page the owner is on, kept on the way back. */
    #[\Override]
    public function actionQuery(): array
    {
        return $this->page > 1 ? ['page' => $this->page] : [];
    }

    public function isEmpty(): bool
    {
        return [] === $this->openAsks && [] === $this->looseItems && 0 === $this->closedAskTotal;
    }
}
