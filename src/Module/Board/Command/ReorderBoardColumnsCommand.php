<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

final readonly class ReorderBoardColumnsCommand
{
    /**
     * @param string $order every column id of the board, comma-separated, in the new order
     */
    public function __construct(
        public Project $project,
        public string $order,
        public string $expectedOrder,
    ) {
    }
}
