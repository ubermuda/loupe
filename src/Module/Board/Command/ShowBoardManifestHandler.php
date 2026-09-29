<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardLanes;
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
        $laneKeys = [];
        foreach (array_filter([...$board->lanes, $board->otherCards]) as $lane) {
            $laneKey = null === $lane->epic ? BoardLanes::OTHER : (string) $lane->epic->id;
            if (null !== $lane->epic) {
                $laneEpics[$laneKey] = true;
                $laneKeys[$laneKey] = $laneKey;
            }
            foreach ($lane->cells as $cards) {
                foreach ($cards as $card) {
                    $laneKeys[(string) $card->id] = $laneKey;
                }
            }
        }

        $cards = [];
        $terminalTotals = [];
        foreach ($board->columns as $view) {
            if (null !== $view->terminalTotal) {
                $terminalTotals[(string) $view->column->id] = $view->terminalTotal;
            }
            foreach ($view->cards as $card) {
                $id = (string) $card->id;
                $entry = [$id, $this->digest->forCard(
                    $card,
                    $board->pendingComments[$id] ?? 0,
                    $board->documentCounts[$id] ?? 0,
                    $card->pullRequests->count(),
                    $board->progress[$id] ?? null,
                    $board->runWarnings[$id] ?? null,
                    $board->badges[$id] ?? [],
                ), (string) $view->column->id, $laneKeys[$id] ?? null];
                // A lane epic has a list row and no card face.
                if (isset($laneEpics[$id])) {
                    $entry[] = true;
                }
                $cards[] = $entry;
            }
        }

        return new BoardManifestView($command->project, $cards, $board->structureDigest, $terminalTotals);
    }
}
