<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\BoardColumnRepository;

final readonly class ListBoardColumnsHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
    ) {
    }

    public function __invoke(ListBoardColumnsCommand $command): ListBoardColumnsView
    {
        return new ListBoardColumnsView($this->boardColumns->findForProject($command->project));
    }
}
