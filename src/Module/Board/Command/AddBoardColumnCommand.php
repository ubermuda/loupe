<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\LabelTone;
use App\Module\Project\Entity\Project;

final readonly class AddBoardColumnCommand
{
    public function __construct(
        public Project $project,
        public string $label,
        /** Null picks a colour no column on the board uses. */
        public ?LabelTone $tone = null,
    ) {
    }
}
