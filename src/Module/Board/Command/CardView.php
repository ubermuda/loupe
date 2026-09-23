<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;

/** Everything one card page renders. */
final readonly class CardView
{
    /**
     * @param list<CardSiteReviewComment> $siteReviewLinks
     * @param list<RelatedCard>           $relatedCards
     */
    public function __construct(
        public Card $card,
        public array $siteReviewLinks,
        public array $relatedCards,
    ) {
    }
}
