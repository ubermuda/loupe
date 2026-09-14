<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;

/**
 * The shapes every inbox tool returns an item in.
 *
 * @phpstan-type InboxItemListSummary array{itemId: string, number: int, kind: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string}
 * @phpstan-type InboxItemListRow array{itemId: string, number: int, kind: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string, options: list<string>, selectedOptions: list<int>, answerText: ?string, closeNote: ?string}
 * @phpstan-type InboxItemCardSummary array{cardId: string, number: int, title: string}
 * @phpstan-type InboxItemDocumentSummary array{documentId: string, title: string}
 * @phpstan-type InboxItemAskSummary array{askId: string, sessionId: string, closedAt: ?string}
 * @phpstan-type InboxItemSummary array{itemId: string, number: int, kind: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string, body: ?string, options: list<string>, multiple: bool, freeText: bool, selectedOptions: list<int>, answerText: ?string, closeNote: ?string, cards: list<InboxItemCardSummary>, documents: list<InboxItemDocumentSummary>, asks: list<InboxItemAskSummary>}
 * @phpstan-type InboxAskSummary array{askId: string, extended: bool, closed: bool, items: list<InboxItemListSummary>}
 */
final readonly class InboxItemPayload
{
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
        return array_map(fn (InboxItem $item): array => [
            ...$this->forListItem($item),
            'options' => array_values($item->options),
            'selectedOptions' => array_values($item->selectedOptions),
            'answerText' => $item->answerText,
            'closeNote' => $item->closeNote,
        ], $items);
    }

    /**
     * @param list<InboxAsk> $asks every ask that holds the item
     *
     * @return InboxItemSummary
     */
    public function forItem(InboxItem $item, array $asks): array
    {
        return [
            ...$this->forListItem($item),
            'body' => $item->body,
            'options' => array_values($item->options),
            'multiple' => $item->multiple,
            'freeText' => $item->freeText,
            'selectedOptions' => array_values($item->selectedOptions),
            'answerText' => $item->answerText,
            'closeNote' => $item->closeNote,
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
}
