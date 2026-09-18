<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReply;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Repository\InboxReplyRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Review\Repository\ReviewRepository;

/**
 * The shapes every inbox tool returns an item in.
 *
 * @phpstan-type InboxItemListSummary array{itemId: string, number: int, kind: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string}
 * @phpstan-type InboxReviewWithdrawalSummary array{reviewId: string, reviewerId: string, reviewerName: string, submittedAt: string}
 * @phpstan-type InboxReviewSummary array{targetKind: string, targetLabel: string, documentId: ?string, pullRequestId: ?string, verdict: ?string, note: ?string, reviewerId: ?string, reviewerName: ?string, submittedAt: ?string, reviewedVersionNumber: ?int, documentReviewId: ?string, withdrawal: ?InboxReviewWithdrawalSummary}
 * @phpstan-type InboxItemListRow array{itemId: string, number: int, kind: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string, options: list<string>, selectedOptions: list<int>, answerText: ?string, closeNote: ?string, review: ?InboxReviewSummary}
 * @phpstan-type InboxItemCardSummary array{cardId: string, number: int, title: string}
 * @phpstan-type InboxItemDocumentSummary array{documentId: string, title: string}
 * @phpstan-type InboxItemAskSummary array{askId: string, sessionId: string, closedAt: ?string}
 * @phpstan-type InboxReplySummary array{replyId: string, authorId: string, authorName: string, body: string, createdAt: string}
 * @phpstan-type InboxItemSummary array{itemId: string, number: int, kind: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string, body: ?string, options: list<string>, multiple: bool, freeText: bool, selectedOptions: list<int>, answerText: ?string, closeNote: ?string, review: ?InboxReviewSummary, replies: list<InboxReplySummary>, cards: list<InboxItemCardSummary>, documents: list<InboxItemDocumentSummary>, asks: list<InboxItemAskSummary>}
 * @phpstan-type InboxAskSummary array{askId: string, extended: bool, closed: bool, items: list<InboxItemListSummary>}
 */
final readonly class InboxItemPayload
{
    public function __construct(
        private InboxReviewRepository $inboxReviews,
        private ReviewRepository $reviews,
        private InboxReplyRepository $inboxReplies,
    ) {
    }

    /** @return InboxItemListSummary */
    public function forListItem(InboxItem $item): array
    {
        return [
            'itemId' => (string) $item->id,
            // The short per-project label a person says out loud. Not the id.
            'number' => $item->number,
            'kind' => $item->kind->value,
            'title' => $item->title,
            'state' => $item->state->value,
            'blocking' => $item->blocking,
            'createdAt' => $item->createdAt->format(\DATE_ATOM),
            'updatedAt' => $item->updatedAt->format(\DATE_ATOM),
            'closedAt' => $item->closedAt?->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return list<InboxItemListSummary>
     */
    public function forList(array $items): array
    {
        return array_map($this->forListItem(...), $items);
    }

    /**
     * The rows of an inbox_list call that names a reader session. They carry the
     * response, because the call records that the session read it.
     *
     * @param list<InboxItem> $items
     *
     * @return list<InboxItemListRow>
     */
    public function forListRows(array $items): array
    {
        $reviews = [];
        $reviewItems = array_values(array_filter($items, static fn (InboxItem $item): bool => InboxItemKind::Review === $item->kind));
        foreach ($this->inboxReviews->findForItems($reviewItems) as $review) {
            $reviews[(string) $review->item->id] = $this->forReview($review);
        }

        return array_map(fn (InboxItem $item): array => [
            ...$this->forListItem($item),
            'options' => array_values($item->options),
            'selectedOptions' => array_values($item->selectedOptions),
            'answerText' => $item->answerText,
            'closeNote' => $item->closeNote,
            'review' => $reviews[(string) $item->id] ?? null,
        ], $items);
    }

    /**
     * @param list<InboxAsk> $asks every ask that holds the item
     *
     * @return InboxItemSummary
     */
    public function forItem(InboxItem $item, array $asks): array
    {
        $review = InboxItemKind::Review === $item->kind ? $this->inboxReviews->findOneBy(['item' => $item]) : null;

        return [
            ...$this->forListItem($item),
            'body' => $item->body,
            'options' => array_values($item->options),
            'multiple' => $item->multiple,
            'freeText' => $item->freeText,
            'selectedOptions' => array_values($item->selectedOptions),
            'answerText' => $item->answerText,
            'closeNote' => $item->closeNote,
            'review' => null === $review ? null : $this->forReview($review),
            'replies' => array_map(static fn (InboxReply $reply): array => [
                'replyId' => (string) $reply->id,
                'authorId' => (string) $reply->author->id,
                'authorName' => $reply->author->fullName,
                'body' => $reply->body,
                'createdAt' => $reply->createdAt->format(\DATE_ATOM),
            ], $this->inboxReplies->findForItem($item)),
            'cards' => array_map(
                static fn (InboxItemCard $link): array => [
                    'cardId' => (string) $link->card->id,
                    'number' => $link->card->number,
                    'title' => $link->card->title,
                ],
                array_values($item->cards->toArray()),
            ),
            'documents' => array_map(
                static fn (InboxItemDocument $link): array => [
                    'documentId' => (string) $link->document->id,
                    'title' => $link->document->title,
                ],
                array_values($item->documents->toArray()),
            ),
            'asks' => array_map(
                static fn (InboxAsk $ask): array => [
                    'askId' => (string) $ask->id,
                    'sessionId' => (string) $ask->sessionId,
                    'closedAt' => $ask->closedAt?->format(\DATE_ATOM),
                ],
                $asks,
            ),
        ];
    }

    /**
     * @param list<InboxItem> $items the items this call handed over
     *
     * @return InboxAskSummary
     */
    public function forAsk(InboxAsk $ask, array $items, bool $extended): array
    {
        return [
            'askId' => (string) $ask->id,
            'extended' => $extended,
            'closed' => null !== $ask->closedAt,
            'items' => $this->forList($items),
        ];
    }

    /** @return InboxReviewSummary */
    private function forReview(InboxReview $review): array
    {
        $withdrawal = null === $review->documentReview ? null : $this->reviews->findWithdrawalOf($review->documentReview);

        return [
            'targetKind' => $review->targetKind->value,
            'targetLabel' => $review->targetLabel,
            'documentId' => null === $review->document ? null : (string) $review->document->id,
            'pullRequestId' => null === $review->pullRequest ? null : (string) $review->pullRequest->id,
            'verdict' => $review->verdict?->value,
            'note' => $review->note,
            'reviewerId' => null === $review->reviewer ? null : (string) $review->reviewer->id,
            'reviewerName' => $review->reviewer?->fullName,
            'submittedAt' => $review->submittedAt?->format(\DATE_ATOM),
            'reviewedVersionNumber' => $review->reviewedVersionNumber,
            'documentReviewId' => null === $review->documentReview ? null : (string) $review->documentReview->id,
            'withdrawal' => null === $withdrawal ? null : [
                'reviewId' => (string) $withdrawal->id,
                'reviewerId' => (string) $withdrawal->reviewer->id,
                'reviewerName' => $withdrawal->reviewer->fullName,
                'submittedAt' => $withdrawal->submittedAt->format(\DATE_ATOM),
            ],
        ];
    }
}
