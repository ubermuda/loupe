<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;

/**
 * The one shape every board tool returns a card in, so a card read by card_list
 * and a card read by card_get describe themselves the same way.
 *
 * @phpstan-type CardPullRequestSummary array{url: string, forge: string, repository: ?string, number: ?int}
 * @phpstan-type CardSummary array{cardId: string, number: int, title: string, body: string, type: string, priority: string, status: string, origin: string, position: int, completedAt: ?string, createdAt: string, updatedAt: string, pullRequests: list<CardPullRequestSummary>}
 */
final readonly class CardPayload
{
    /** @return CardSummary */
    public function forCard(Card $card): array
    {
        return [
            'cardId' => (string) $card->id,
            // The short per-project label a person says out loud. Not the id.
            'number' => $card->number,
            'title' => $card->title,
            'body' => $card->body,
            'type' => $card->type->value,
            // The name, not the backing integer: the number orders the board and
            // is not the vocabulary a caller writes with.
            'priority' => $card->priority->label(),
            'status' => $card->status->value,
            'origin' => $card->origin->value,
            'position' => $card->position,
            'completedAt' => $card->completedAt?->format(\DATE_ATOM),
            'createdAt' => $card->createdAt->format(\DATE_ATOM),
            'updatedAt' => $card->updatedAt->format(\DATE_ATOM),
            'pullRequests' => array_map(
                static fn (CardPullRequest $link): array => [
                    'url' => $link->url,
                    'forge' => $link->forge->value,
                    'repository' => $link->repository,
                    'number' => $link->number,
                ],
                array_values($card->pullRequests->toArray()),
            ),
        ];
    }
}
