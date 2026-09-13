<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;

class DeleteBoardColumnRequest
{
    public function __construct(
        /** Where the column's cards go. The handler requires it only when the column holds cards. */
        public ?BoardColumn $target = null,
    ) {
    }
}
