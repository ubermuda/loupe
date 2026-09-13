<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

final readonly class SetDefaultBoardColumnCommand
{
    public function __construct(
        public BoardColumn $column,
    ) {
    }
}
