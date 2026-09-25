<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Command\CardView;
use App\Module\Board\Command\RelatedCard;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

/**
 * The one shape every board tool returns a card in, so a card read by card_list
 * and a card read by card_get describe themselves the same way.
 *
 * @phpstan-type CardPullRequestSummary array{pullRequestId: string, url: string, forge: string, repository: ?string, number: ?int}
 * @phpstan-type CardSiteReviewCommentSummary array{commentId: string, body: string, url: string, status: string, createdAt: string}
 * @phpstan-type CardDocumentSummary array{documentId: string, title: string, status: string}
 * @phpstan-type CardRelatedCardSummary array{cardId: string, number: int, title: string, status: string, kind: string}
 * @phpstan-type CardRefSummary array{cardId: string, number: int, title: string, status: string}
 * @phpstan-type CardSummary array{cardId: string, number: int, title: string, body: string, type: string, status: string, reporter: string, position: int, completedAt: ?string, createdAt: string, updatedAt: string, pullRequests: list<CardPullRequestSummary>, documents: list<CardDocumentSummary>, siteReviewComments: list<CardSiteReviewCommentSummary>, relatedCards: list<CardRelatedCardSummary>, parent: ?CardRefSummary, laneEnabled: bool, children: list<CardRefSummary>, progress: ?array{done: int, total: int}}
 * @phpstan-type CardListSummary array{cardId: string, number: int, title: string, type: string, status: string, reporter: string, parentCardId: ?string, updatedAt: string}
 */
final readonly class CardPayload
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardLinkRepository $cardLinks,
        private CardRepository $cards,
    ) {
    }

    /**
     * One card whose links, related cards and children the caller already
     * holds, from {@see \App\Module\Board\Command\ShowCardHandler}.
     *
     * @return CardSummary
     */
    public function forCard(CardView $view): array
    {
        return $this->render($view->card, $view->siteReviewLinks, $view->relatedCards, $view->children);
    }

    /**
     * Many cards in one read, so a board-sized list costs one comment query,
     * one card link query, one children query and one parent query rather
     * than one of each per card.
     *
     * @param list<Card> $cards
     *
     * @return list<CardSummary>
     */
    public function forCards(array $cards): array
    {
        $commentsByCard = $this->cardSiteReviewComments->findForCards($cards);
        $linksByCard = $this->cardLinks->findForCards($cards);
        $epics = array_values(array_filter($cards, static fn (Card $card): bool => CardType::Epic === $card->type));
        $childrenByCard = [] === $epics ? [] : $this->cards->findChildrenOfCards($epics);
        $this->cards->loadParentsOf($cards);

        return array_map(
            fn (Card $card): array => $this->render(
                $card,
                $commentsByCard[(string) $card->id] ?? [],
                array_map(
                    static fn (CardLink $link): RelatedCard => new RelatedCard($link->otherThan($card), $link->kindFor($card)),
                    $linksByCard[(string) $card->id] ?? [],
                ),
                $childrenByCard[(string) $card->id] ?? [],
            ),
            $cards,
        );
    }

    /**
     * The lean shape a board listing reads in, with no body and none of the
     * link sets.
     *
     * It runs no comment or card link query at all, which is the point: the
     * full shape costs both whether or not the caller reads them back.
     *
     * @param list<Card> $cards
     *
     * @return list<CardListSummary>
     */
    public function forCardList(array $cards): array
    {
        return array_map(
            static fn (Card $card): array => [
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'title' => $card->title,
                'type' => $card->type->value,
                'status' => $card->column->slug,
                'reporter' => $card->reporter->value,
                // The id alone, which a parent proxy holds without a query.
                'parentCardId' => null === $card->parent ? null : (string) $card->parent->id,
                'updatedAt' => $card->updatedAt->format(\DATE_ATOM),
            ],
            $cards,
        );
    }

    /**
     * @param list<CardSiteReviewComment> $links
     * @param list<RelatedCard>           $relatedCards
     * @param list<Card>                  $children     empty for a card that is not an epic
     *
     * @return CardSummary
     */
    private function render(Card $card, array $links, array $relatedCards, array $children): array
    {
        $progress = CardType::Epic === $card->type
            ? ['done' => \count(array_filter($children, static fn (Card $child): bool => $child->column->terminal)), 'total' => \count($children)]
            : null;

        return [
            'cardId' => (string) $card->id,
            // The short per-project label a person says out loud. Not the id.
            'number' => $card->number,
            'title' => $card->title,
            'body' => $card->body,
            'type' => $card->type->value,
            'status' => $card->column->slug,
            'reporter' => $card->reporter->value,
            'position' => $card->position,
            'completedAt' => $card->completedAt?->format(\DATE_ATOM),
            'createdAt' => $card->createdAt->format(\DATE_ATOM),
            'updatedAt' => $card->updatedAt->format(\DATE_ATOM),
            'pullRequests' => array_map(
                static fn (CardPullRequest $link): array => [
                    'pullRequestId' => (string) $link->id,
                    'url' => $link->url,
                    'forge' => $link->forge->value,
                    'repository' => $link->repository,
                    'number' => $link->number,
                ],
                array_values($card->pullRequests->toArray()),
            ),
            // The documents this card's work is written up in. Read only here:
            // a document is written and revised through its own tools.
            'documents' => array_map(
                static fn (CardDocument $link): array => [
                    'documentId' => (string) $link->document->id,
                    'title' => $link->document->title,
                    'status' => $link->document->status->value,
                ],
                array_values($card->documents->toArray()),
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
            // The kind reads from this card's side: the target of a blocks
            // link reads blocked-by.
            'relatedCards' => array_map(
                static fn (RelatedCard $related): array => [
                    'cardId' => (string) $related->card->id,
                    'number' => $related->card->number,
                    'title' => $related->card->title,
                    'status' => $related->card->column->slug,
                    'kind' => $related->kind->value,
                ],
                $relatedCards,
            ),
            'parent' => null === $card->parent ? null : self::reference($card->parent),
            'laneEnabled' => $card->laneEnabled,
            'children' => array_map(self::reference(...), $children),
            'progress' => $progress,
        ];
    }

    /** @return CardRefSummary */
    private static function reference(Card $card): array
    {
        return [
            'cardId' => (string) $card->id,
            'number' => $card->number,
            'title' => $card->title,
            'status' => $card->column->slug,
        ];
    }
}
