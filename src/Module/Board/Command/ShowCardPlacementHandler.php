<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardColumnCards;
use App\Module\Board\Service\BoardLanes;

final readonly class ShowCardPlacementHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardDocumentRepository $cardDocuments,
        private BoardLanes $boardLanes,
    ) {
    }

    public function __invoke(ShowCardPlacementCommand $command): CardPlacementView
    {
        $cardId = null === $command->card ? null : (string) $command->card->id;
        $counts = [];
        $terminalTotals = [];
        $found = null;
        $column = null;
        $after = null;
        $rowAfter = null;
        $previousRow = null;
        $columnViews = [];

        // Every column is read as the board page reads it, so the list order
        // across columns and the counts come from the same query as the page.
        foreach ($this->boardColumns->findForProject($command->project) as $boardColumn) {
            $shown = $this->columnCards->shown($boardColumn);
            $counts[(string) $boardColumn->id] = \count($shown);
            $columnViews[] = new BoardColumnView($boardColumn, $shown, \count($shown));
            if ($boardColumn->terminal) {
                $terminalTotals[(string) $boardColumn->id] = $this->cards->countInColumn($boardColumn);
            }

            $previousInColumn = null;
            foreach ($shown as $card) {
                $id = (string) $card->id;
                if ($id === $cardId) {
                    [$found, $column, $after, $rowAfter] = [$card, $boardColumn, $previousInColumn, $previousRow];
                }
                $previousInColumn = $id;
                $previousRow = $id;
            }
        }

        [$lane, $laneHead, $laneAfter, $previousEpic] = [null, false, null, null];
        [$lanes, $otherCards] = $this->boardLanes->sort($columnViews);
        foreach (null === $otherCards ? [] : [...$lanes, $otherCards] as $laneView) {
            $key = null === $laneView->epic ? BoardLanes::OTHER : (string) $laneView->epic->id;
            if ($key === $cardId) {
                [$laneHead, $after, $laneAfter] = [true, null, $previousEpic];
            }
            if (null !== $laneView->epic) {
                $previousEpic = $key;
            }
            foreach ($laneView->cells as $cell) {
                foreach ($cell as $index => $card) {
                    if ((string) $card->id === $cardId) {
                        [$lane, $after] = [$key, 0 === $index ? null : (string) $cell[$index - 1]->id];
                    }
                }
            }
        }

        $pending = null === $found ? 0 : ($this->cardSiteReviewComments->pendingCountsForProject($command->project)[(string) $found->id] ?? 0);
        $documentCount = null === $found ? 0 : ($this->cardDocuments->countsForProject($command->project)[(string) $found->id] ?? 0);

        $progress = null;
        if (CardType::Epic === $found?->type) {
            $children = $this->cards->childProgressForProject($command->project)[(string) $found->id] ?? ['done' => 0, 'total' => 0];
            $progress = new CardProgress($children['done'], $children['total']);
        }

        return new CardPlacementView($found, $column, $after, $rowAfter, $pending, $documentCount, $counts, $terminalTotals, $progress, $lane, $laneHead, $laneAfter);
    }
}
