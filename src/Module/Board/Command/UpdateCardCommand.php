<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;

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
 *
 * $expectedFingerprint is the Card::contentFingerprint() the editor opened.
 * A card whose text differs from it is refused unless $confirmOverwrite.
 *
 * $expectedColumn is the column the caller checked the card was in. A card
 * that another request moved out of it since is refused.
 *
 * A move to another column closes every open interactive run of the card. A
 * run that $openInteractiveRun opens in the same update stays open.
 *
 * $onlyFromColumn and $onlyFromOpenColumn are checked under the lock too. A
 * card that no longer sits there is left alone, with no change and no error.
 *
 * $cause says why the card moved, for the card's history: an app rule or an agent's run.
 * A move that opens an interactive run and names no cause names that run.
 *
 * $unmanageBy holds the card in the same transaction, for a caller that
 * checked the person may manage the project. A refused update holds nothing.
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
        public Actor $actor,
        public ?string $title = null,
        public ?string $body = null,
        public ?string $type = null,
        public ?BoardColumn $column = null,
        public ?array $pullRequestUrls = null,
        public ?array $documentIds = null,
        public ?int $position = null,
        public ?array $relatedCards = null,
        public ?string $parentCardId = null,
        public ?bool $laneEnabled = null,
        public ?string $beforeCardId = null,
        public ?string $afterCardId = null,
        public ?string $expectedFingerprint = null,
        public bool $confirmOverwrite = false,
        public ?OpenInteractiveRun $openInteractiveRun = null,
        public ?BoardColumn $expectedColumn = null,
        public ?BoardColumn $onlyFromColumn = null,
        public bool $onlyFromOpenColumn = false,
        public ?CardEventCause $cause = null,
        public ?User $unmanageBy = null,
    ) {
    }
}
