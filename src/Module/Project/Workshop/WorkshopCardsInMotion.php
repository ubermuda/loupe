<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

/** The cards the Workshop shows as in motion, and how many more it leaves out. */
final readonly class WorkshopCardsInMotion
{
    public function __construct(
        /** @var list<WorkshopCard> */
        public array $cards = [],
        public int $more = 0,
    ) {
    }
}
