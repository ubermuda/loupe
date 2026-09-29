<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;

final readonly class RankBacklogCardCommand
{
    /**
     * @param ?string $beforeCardId the Backlog card the card lands above; it wins over $afterCardId
     * @param ?string $afterCardId  the Backlog card the card lands below, when no card is below it
     */
    public function __construct(
        public Card $card,
        public CardReporter $actor,
        public ?string $beforeCardId = null,
        public ?string $afterCardId = null,
    ) {
    }
}
