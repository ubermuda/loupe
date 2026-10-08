<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The set of documents linked to a card has changed. It carries ids rather
 * than the card, like CardChanged, so it stays valid for a deleted card.
 */
final readonly class CardDocumentsChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
    ) {
    }
}
