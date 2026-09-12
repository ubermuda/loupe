<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;

/**
 * Every field is optional and null means "leave it alone".
 *
 * $pullRequestUrls and $documentIds are the places where null and an empty
 * array differ: null keeps the links the card has, and an empty array removes
 * them all.
 *
 * $reporter is absent on purpose. It records who first raised the card.
 */
final readonly class UpdateCardCommand
{
    /**
     * @param list<string>|null $pullRequestUrls
     * @param list<string>|null $documentIds
     * @param ?int              $position        the rank the card takes inside its group, counting
     *                                           from 0; null leaves the rank alone, and a rank past
     *                                           the end of the group is clamped to it
     */
    public function __construct(
        public Card $card,
        public ?string $title = null,
        public ?string $body = null,
        public ?CardType $type = null,
        public ?CardPriority $priority = null,
        public ?CardStatus $status = null,
        public ?array $pullRequestUrls = null,
        public ?array $documentIds = null,
        public ?int $position = null,
    ) {
    }
}
