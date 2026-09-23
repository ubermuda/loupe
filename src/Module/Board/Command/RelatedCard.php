<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;

/** The other card of one link, with the kind as the reading card reads it. */
final readonly class RelatedCard
{
    public function __construct(
        public Card $card,
        public CardLinkKind $kind,
    ) {
    }
}
