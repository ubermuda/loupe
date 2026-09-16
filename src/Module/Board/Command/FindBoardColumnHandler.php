<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;

final readonly class FindBoardColumnHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
    ) {
    }

    public function __invoke(FindBoardColumnCommand $command): ?BoardColumn
    {
        return $this->boardColumns->findOneByIdAndProjectId($command->id, (string) $command->project->id);
    }
}
