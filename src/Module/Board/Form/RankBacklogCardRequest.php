<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

class RankBacklogCardRequest
{
    public function __construct(
        /** The visible row below the drop. */
        public ?string $beforeCardId = null,
        /** The visible row above the drop, when no row is below it. */
        public ?string $afterCardId = null,
    ) {
    }
}
