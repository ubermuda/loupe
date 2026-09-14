<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxLinkedPage;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** The items linked to one card or one document, as the section on that page lists them. */
final readonly class LinkedInboxItemsView implements InboxItemsView
{
    /** @var list<InboxItem> */
    public array $openItems;

    /** @var list<InboxItem> */
    public array $closedItems;

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
    ) {
        $this->openItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemState::Open === $item->state));
        $this->closedItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemState::Open !== $item->state));
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
        $heldByClosedAsk = [] !== array_filter(
            $this->asksByItem[(string) $item->id] ?? [],
            static fn (InboxAsk $ask): bool => null !== $ask->closedAt,
        );

        return $item->state->acceptsResponse($heldByClosedAsk);
    }

    #[\Override]
    public function actionQuery(): array
    {
        return ['returnTo' => $this->page->value, 'returnId' => $this->targetId->toRfc4122()];
    }

    public function isEmpty(): bool
    {
        return [] === $this->openItems && [] === $this->closedItems;
    }
}
