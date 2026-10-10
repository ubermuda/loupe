<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A card that a move rule holds for an open blocker alone, since the first pass that held it. */
final readonly class BlockerHold
{
    public function __construct(
        public string $cardId,
        public \DateTimeImmutable $since,
        public int $blockerNumber,
        public string $blockerTitle,
    ) {
    }
}
