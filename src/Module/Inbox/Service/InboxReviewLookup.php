<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Repository\InboxReviewRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The review behind a review item, read once per request. A page loads the
 * reviews of every item it shows in one query, and each row, panel and voter
 * check reads from that. It keeps managed entities, so a change to a review in
 * the same request shows through. An item it holds no review for is queried.
 */
final class InboxReviewLookup implements ResetInterface
{
    /** @var array<string, InboxReview> */
    private array $byItem = [];

    public function __construct(
        private readonly InboxReviewRepository $inboxReviews,
    ) {
    }

    /** @param list<InboxItem> $items */
    public function preload(array $items): void
    {
        $reviewItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemKind::Review === $item->kind));
        foreach ($this->inboxReviews->findForItems($reviewItems) as $review) {
            $this->byItem[(string) $review->item->id] = $review;
        }
    }

    public function forItem(InboxItem $item): ?InboxReview
    {
        $key = (string) $item->id;
        if (isset($this->byItem[$key])) {
            return $this->byItem[$key];
        }

        $review = $this->inboxReviews->findOneBy(['item' => $item]);
        if (null !== $review) {
            $this->byItem[$key] = $review;
        }

        return $review;
    }

    #[\Override]
    public function reset(): void
    {
        $this->byItem = [];
    }
}
