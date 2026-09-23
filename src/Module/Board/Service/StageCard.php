<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Review\Entity\Document;

/**
 * The card whose lifecycle stage a document reviews: a linked card that sits in
 * the column the stage starts from. Two such cards resolve to the lowest number.
 */
final readonly class StageCard
{
    public function __construct(
        private LifecycleStages $stages,
    ) {
    }

    /** @param list<CardDocument> $links the links of this document */
    public function forDocument(Document $document, array $links): ?Card
    {
        $stage = $this->stages->forDocument($document);
        if (null === $stage) {
            return null;
        }

        $found = null;
        foreach ($links as $link) {
            $card = $link->card;
            if ($card->column->slug === $stage['from'] && (null === $found || $card->number < $found->number)) {
                $found = $card;
            }
        }

        return $found;
    }
}
