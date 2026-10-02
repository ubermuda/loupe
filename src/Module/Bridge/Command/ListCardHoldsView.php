<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\CardHold;

final readonly class ListCardHoldsView
{
    /** @param list<CardHold> $holds */
    public function __construct(
        public array $holds,
    ) {
    }
}
