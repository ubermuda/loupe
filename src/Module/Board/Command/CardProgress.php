<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** How many children of an epic sit in a terminal column, out of all its children. */
final readonly class CardProgress
{
    public function __construct(
        public int $done,
        public int $total,
    ) {
    }
}
