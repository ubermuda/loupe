<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;

final readonly class RenameBoardColumnCommand
{
    public function __construct(
        public BoardColumn $column,
        public CardReporter $actor,
        public string $label,
    ) {
    }
}
