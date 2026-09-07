<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\CardMove;

/**
 * What one update did, read out of the transaction that did it.
 *
 * The audit records are written after the commit, and the card by then holds
 * only its new values, so what changed is decided inside and reported here.
 */
final readonly class UpdateCardOutcome
{
    public function __construct(
        public ?CardMove $move,
        public bool $titleChanged,
        public bool $bodyChanged,
        public bool $typeChanged,
    ) {
    }
}
