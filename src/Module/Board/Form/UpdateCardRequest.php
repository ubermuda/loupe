<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;

/**
 * Identical fields and constraints to {@see CreateCardRequest}; the only
 * addition is the factory that pre-fills the edit form from the card.
 */
class UpdateCardRequest extends CreateCardRequest
{
    public static function fromCard(Card $card): self
    {
        return new self(
            title: $card->title,
            body: $card->body,
            type: $card->type,
            priority: $card->priority,
            status: $card->status,
            pullRequestUrls: implode("\n", array_map(
                static fn (CardPullRequest $link): string => $link->url,
                $card->pullRequests->toArray(),
            )),
        );
    }
}
