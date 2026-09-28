<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

final readonly class ShowBoardStructureHandler
{
    public function __construct(
        private ShowBoardHandler $showBoard,
    ) {
    }

    /** The board page's own view, so the skeleton and its digest match what the page draws. */
    public function __invoke(ShowBoardStructureCommand $command): BoardView
    {
        return ($this->showBoard)(new ShowBoardCommand($command->project));
    }
}
