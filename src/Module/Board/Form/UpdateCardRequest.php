<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Command\RelatedCard;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;

/**
 * The fields and constraints of {@see CreateCardRequest}, the factory that
 * pre-fills the edit form from the card, and the fingerprint of its text.
 */
class UpdateCardRequest extends CreateCardRequest
{
    /** Card::contentFingerprint() of the text the form opened with. */
    public ?string $contentFingerprint = null;

    /** @param list<RelatedCard> $relatedCards the card's links, each as the card reads it */
    public static function fromCard(Card $card, array $relatedCards): self
    {
        $request = new self(
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
        $request->contentFingerprint = Card::contentFingerprint($card->title, $card->body);

        return $request;
    }
}
