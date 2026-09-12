<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;

final readonly class PreviewBoardColumnRenameView
{
    public function __construct(
        public BoardColumn $column,
        public string $slug,
        /** The translation key of the rule a rename to this label would break. */
        public ?string $refusal,
    ) {
    }
}
