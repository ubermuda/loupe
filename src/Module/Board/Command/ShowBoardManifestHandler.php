<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\CardDigest;

final readonly class ShowBoardManifestHandler
{
    public function __construct(
        private ShowBoardHandler $showBoard,
        private CardDigest $digest,
    ) {
    }

    public function __invoke(ShowBoardManifestCommand $command): BoardManifestView
    {
        // The board page's own view, so each digest reads the inputs the page renders.
        $board = ($this->showBoard)(new ShowBoardCommand($command->project));
        $laneEpics = [];
        foreach ($board->lanes as $lane) {
            if (null !== $lane->epic) {
                $laneEpics[(string) $lane->epic->id] = true;
            }
        }

        $cards = [];
        foreach ($board->columns as $view) {
            foreach ($view->cards as $card) {
                $id = (string) $card->id;
                if (isset($laneEpics[$id])) {
                    continue;
                }
                $cards[] = [$id, $this->digest->forCard(
                    $card,
                    $board->pendingComments[$id] ?? 0,
                    $board->documentCounts[$id] ?? 0,
                    $card->pullRequests->count(),
                    $board->progress[$id] ?? null,
                    $board->runWarnings[$id] ?? null,
                ), (string) $view->column->id];
            }
        }

        return new BoardManifestView($command->project, $cards, $board->structureDigest);
    }
}
