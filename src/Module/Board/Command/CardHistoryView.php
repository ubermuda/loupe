<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\View\CardHistoryEntry;

/**
 * One page of a card's history, newest first. The cursor fields name the last
 * row shown, and are null when no older row exists.
 */
final readonly class CardHistoryView
{
    /** @param list<CardHistoryEntry> $entries */
    public function __construct(
        public Card $card,
        public ?string $beforeId,
        public array $entries,
        public ?string $olderBefore,
        public ?string $olderBeforeId,
    ) {
    }
}
