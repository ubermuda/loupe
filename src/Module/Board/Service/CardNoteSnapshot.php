<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;

/** The pending site-review notes of a card as a verdict copies them. */
final readonly class CardNoteSnapshot
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    /** @return list<array{id: string, url: string, body: string, anchorCount: int}> */
    public function pendingOf(Card $card): array
    {
        $pending = array_filter(
            $this->cardSiteReviewComments->findForCard($card),
            static fn (CardSiteReviewComment $link): bool => SiteReviewCommentStatus::Pending === $link->comment->status,
        );

        return array_values(array_map(
            static fn (CardSiteReviewComment $link): array => [
                'id' => (string) $link->comment->id,
                'url' => $link->comment->url,
                'body' => $link->comment->body,
                'anchorCount' => \count($link->comment->anchors),
            ],
            $pending,
        ));
    }
}
