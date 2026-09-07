<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

/**
 * The one shape every board tool returns a card in, so a card read by card_list
 * and a card read by card_get describe themselves the same way.
 *
 * @phpstan-type CardPullRequestSummary array{url: string, forge: string, repository: ?string, number: ?int}
 * @phpstan-type CardSiteReviewCommentSummary array{commentId: string, body: string, url: string, status: string, createdAt: string}
 * @phpstan-type CardSummary array{cardId: string, number: int, title: string, body: string, type: string, priority: string, status: string, origin: string, position: int, completedAt: ?string, createdAt: string, updatedAt: string, pullRequests: list<CardPullRequestSummary>, siteReviewComments: list<CardSiteReviewCommentSummary>}
 */
final readonly class CardPayload
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    /** @return CardSummary */
    public function forCard(Card $card): array
    {
        return $this->forCards([$card])[0];
    }

    /**
     * Many cards in one read, so a board-sized list costs one comment query
     * rather than one per card.
     *
     * @param list<Card> $cards
     *
     * @return list<CardSummary>
     */
    public function forCards(array $cards): array
    {
        $byCard = $this->cardSiteReviewComments->findForCards($cards);

        return array_map(
            fn (Card $card): array => $this->render($card, $byCard[(string) $card->id] ?? []),
            $cards,
        );
    }

    /**
     * @param list<CardSiteReviewComment> $links
     *
     * @return CardSummary
     */
    private function render(Card $card, array $links): array
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
            // Feedback a reviewer left on a page that named this card. Read
            // only: an agent marks one addressed through
            // site_review_mark_comment_addressed, which owns the status.
            'siteReviewComments' => array_map(
                static fn (CardSiteReviewComment $link): array => [
                    'commentId' => (string) $link->comment->id,
                    'body' => $link->comment->body,
                    'url' => $link->comment->url,
                    'status' => $link->comment->status->value,
                    'createdAt' => $link->comment->createdAt->format(\DATE_ATOM),
                ],
                $links,
            ),
        ];
    }
}
