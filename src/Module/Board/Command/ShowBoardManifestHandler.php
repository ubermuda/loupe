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
        $laneHeads = [];
        $laneKeys = [];
        foreach (array_filter([...$board->lanes, $board->otherCards]) as $lane) {
            $laneKey = null === $lane->epic ? BoardLanes::OTHER : (string) $lane->epic->id;
            if (null !== $lane->epic) {
                $laneHeads[$laneKey] = $this->digest->forLaneHead($lane->epic, $board->progress[$laneKey] ?? null, $board->decks[$laneKey] ?? null);
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
                    $board->states[$id] ?? null,
                ), (string) $view->column->id, $laneKeys[$id] ?? null];
                // A lane epic has a list row and a lane head, and no card face.
                if (isset($laneHeads[$id])) {
                    $entry[] = $laneHeads[$id];
                    unset($laneHeads[$id]);
                }
                $cards[] = $entry;
            }
        }
        // A lane epic in the Backlog has no list row, so its head digest stands in for one.
        foreach ($laneHeads as $id => $headDigest) {
            $cards[] = [$id, $headDigest, (string) $board->backlog->id, $id, $headDigest];
        }

        return new BoardManifestView($command->project, $cards, $board->structureDigest, $terminalTotals, $board->backlogCount);
    }
}
