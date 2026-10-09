<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Workflow\Contract\Actor;

final readonly class DeleteBoardColumnCommand
{
    /**
     * @param ?BoardColumn $target where the column's cards go; required only when it holds any
     */
    public function __construct(
        public BoardColumn $column,
        public Actor $actor,
        public ?BoardColumn $target = null,
    ) {
    }
}
