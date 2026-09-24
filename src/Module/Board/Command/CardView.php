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
     * @param list<Card>                  $children        empty for a card that is not an epic
     * @param ?CardProgress               $progress        null for a card that is not an epic
     */
    public function __construct(
        public Card $card,
        public array $siteReviewLinks,
        public array $relatedCards,
        public array $children = [],
        public ?CardProgress $progress = null,
    ) {
    }
}
