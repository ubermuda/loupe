<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxReviewRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the reconciler to look at some cards again. The Doctrine transport
 * commits the message with the caller's transaction, so a caller may dispatch
 * inside one.
 */
final readonly class CardWaitTrigger
{
    public function __construct(
        private MessageBusInterface $bus,
        private CardDocumentRepository $cardDocuments,
        private InboxReviewRepository $inboxReviews,
    ) {
    }

    /** @param list<string> $cardIds */
    public function forCards(Uuid $projectId, array $cardIds): void
    {
        if ([] === $cardIds) {
            return;
        }

        $this->bus->dispatch(new ReconcileCardWaits($projectId->toRfc4122(), array_values(array_unique($cardIds))));
    }

    public function forDocument(Uuid $projectId, Uuid $documentId): void
    {
        $this->forCards($projectId, $this->cardDocuments->findCardIdsForDocument($documentId));
    }

    /**
     * An open review item of an agent on a document holds back the wait of
     * that document, so a change to such an item asks for its cards.
     *
     * @param list<InboxItem> $items
     */
    public function forReviewItems(array $items): void
    {
        $reviewItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemKind::Review === $item->kind));
        $documents = [];
        foreach ($this->inboxReviews->findForItems($reviewItems) as $review) {
            if (null !== $review->document) {
                $documents[(string) $review->document->id] = $review->document;
            }
        }

        foreach ($documents as $document) {
            $this->forDocument(
                $document->project->id ?? throw new \LogicException('Project has no id.'),
                $document->id ?? throw new \LogicException('Document has no id.'),
            );
        }
    }
}
