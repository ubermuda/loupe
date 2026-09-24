<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;

/**
 * $card and $actor are required. $actor is who makes this change, and a move
 * publishes it to the outbox. Every other field is optional, and null means
 * "leave it alone".
 *
 * $pullRequestUrls, $documentIds and $relatedCards are the places where null
 * and an empty array differ: null keeps the links the card has, and an empty
 * array removes them all. $relatedCards replaces every link that touches the
 * card, whichever card wrote it.
 *
 * $parentCardId has three states: null keeps the parent, an empty string
 * clears it, and a card id sets it.
 *
 * $beforeCardId and $afterCardId name a card of the target column that the
 * card lands above or below. A named neighbour wins over $position, and one
 * the column does not hold sends the card to the end.
 *
 * $reporter is absent on purpose. It records who first raised the card.
 */
final readonly class UpdateCardCommand
{
    /**
     * @param list<string>|null        $pullRequestUrls
     * @param list<string>|null        $documentIds
     * @param ?int                     $position        the rank the card takes inside its column, counting
     *                                                  from 0; null leaves the rank alone, and a rank past
     *                                                  the end of the column is clamped to it
     * @param list<CardLinkInput>|null $relatedCards
     */
    public function __construct(
        public Card $card,
        public CardReporter $actor,
        public ?string $title = null,
        public ?string $body = null,
        public ?CardType $type = null,
        public ?BoardColumn $column = null,
        public ?array $pullRequestUrls = null,
        public ?array $documentIds = null,
        public ?int $position = null,
        public ?array $relatedCards = null,
        public ?string $parentCardId = null,
        public ?bool $laneEnabled = null,
        public ?string $beforeCardId = null,
        public ?string $afterCardId = null,
    ) {
    }
}
