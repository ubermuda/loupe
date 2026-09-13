<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

final readonly class DeleteBoardColumnCommand
{
    /**
     * @param ?BoardColumn $target where the column's cards go; required only when it holds any
     */
    public function __construct(
        public BoardColumn $column,
        public ?BoardColumn $target = null,
    ) {
    }
}
