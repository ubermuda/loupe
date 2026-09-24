<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Command\RelatedCard;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;

/**
 * Identical fields and constraints to {@see CreateCardRequest}; the only
 * addition is the factory that pre-fills the edit form from the card.
 */
class UpdateCardRequest extends CreateCardRequest
{
    /** @param list<RelatedCard> $relatedCards the card's links, each as the card reads it */
    public static function fromCard(Card $card, array $relatedCards): self
    {
        return new self(
            title: $card->title,
            body: $card->body,
            type: $card->type,
            column: $card->column,
            pullRequestUrls: implode("\n", array_map(
                static fn (CardPullRequest $link): string => $link->url,
                $card->pullRequests->toArray(),
            )),
            relatedCards: array_map(
                static fn (RelatedCard $related): CardLinkRowRequest => new CardLinkRowRequest($related->card, $related->kind),
                $relatedCards,
            ),
            parent: $card->parent,
        );
    }
}
