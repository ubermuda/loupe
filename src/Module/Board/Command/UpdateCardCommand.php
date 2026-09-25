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
 * $reporter is absent on purpose. It records who first raised the card.
 *
 * $expectedFingerprint is the Card::contentFingerprint() the editor opened.
 * A card whose text differs from it is refused unless $confirmOverwrite.
 *
 * A move to another column closes every open interactive run of the card. A
 * run that $openInteractiveRun opens in the same update stays open.
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
        public ?string $expectedFingerprint = null,
        public bool $confirmOverwrite = false,
        public ?OpenInteractiveRun $openInteractiveRun = null,
    ) {
    }
}
