<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxLinkedPage;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** The items linked to one card or one document, as the section on that page lists them. */
final readonly class LinkedInboxItemsView implements InboxItemsView
{
    /** @var list<InboxItem> */
    public array $openItems;

    /**
     * How many closed items the section shows. The inbox page lists the rest,
     * because every item enters through an ask and that page lists every ask.
     */
    public const int CLOSED_ITEMS_SHOWN = 10;

    /** @var list<InboxItem> */
    public array $closedItems;

    /** Every closed item linked to the page, shown or not. */
    public int $closedTotal;

    /**
     * @param list<InboxItem>               $items      by number
     * @param array<string, list<InboxAsk>> $asksByItem item id to the asks that hold it, oldest first
     */
    public function __construct(
        public Project $project,
        public InboxLinkedPage $page,
        public Uuid $targetId,
        array $items,
        private array $asksByItem,
        /** The older document version the page shows, or null for the current one. */
        public ?int $versionNumber = null,
        ?int $focusedItemNumber = null,
    ) {
        $this->openItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemState::Open === $item->state));
        $closed = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemState::Open !== $item->state));
        usort($closed, static fn (InboxItem $a, InboxItem $b): int => [$b->closedAt, $b->number] <=> [$a->closedAt, $a->number]);
        $this->closedTotal = \count($closed);
        $shownClosed = \array_slice($closed, 0, self::CLOSED_ITEMS_SHOWN);
        foreach ($closed as $index => $item) {
            if ($index >= self::CLOSED_ITEMS_SHOWN && $item->number === $focusedItemNumber) {
                array_pop($shownClosed);
                $shownClosed[] = $item;
                break;
            }
        }
        $this->closedItems = $shownClosed;
    }

    public function hasMoreClosedItems(): bool
    {
        return $this->closedTotal > \count($this->closedItems);
    }

    /** The newest ask that holds the item, whose context the section shows. */
    public function latestAsk(InboxItem $item): ?InboxAsk
    {
        $asks = $this->asksByItem[(string) $item->id] ?? [];

        return [] === $asks ? null : $asks[\count($asks) - 1];
    }

    /** Each item shows once in the section, so its forms always render where it shows. */
    #[\Override]
    public function isHome(InboxItem $item, ?InboxAsk $ask = null): bool
    {
        return true;
    }

    #[\Override]
    public function acceptsResponse(InboxItem $item): bool
    {
        if (InboxItemKind::Review === $item->kind && InboxItemState::Open !== $item->state) {
            return false;
        }
        $heldByClosedAsk = [] !== array_filter(
            $this->asksByItem[(string) $item->id] ?? [],
            static fn (InboxAsk $ask): bool => null !== $ask->closedAt,
        );

        return $item->state->acceptsResponse($heldByClosedAsk);
    }

    #[\Override]
    public function actionQuery(): array
    {
        $query = [
            InboxReturnTarget::PAGE_QUERY => $this->page->value,
            InboxReturnTarget::ID_QUERY => $this->targetId->toRfc4122(),
        ];
        if (null !== $this->versionNumber) {
            $query[InboxReturnTarget::VERSION_QUERY] = $this->versionNumber;
        }

        return $query;
    }

    public function isEmpty(): bool
    {
        return [] === $this->openItems && 0 === $this->closedTotal;
    }
}
