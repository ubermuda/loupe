<?php

declare(strict_types=1);

namespace App\Outbox\Command;

/** Outcome of one drain pass over the outbox. */
final readonly class OutboxDrainResult
{
    public function __construct(
        public int $published,
        public int $failed,
    ) {
    }
}
