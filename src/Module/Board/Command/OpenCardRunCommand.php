<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use Symfony\Component\Uid\Uuid;

/** $column moves the card in the same transaction, and null leaves it where it is. */
final readonly class OpenCardRunCommand
{
    public function __construct(
        public Card $card,
        public CardReporter $actor,
        public Uuid $sessionId,
        public string $name,
        public ?BoardColumn $column = null,
    ) {
    }
}
