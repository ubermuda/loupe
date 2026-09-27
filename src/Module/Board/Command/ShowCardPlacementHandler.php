<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardColumnCards;

final readonly class ShowCardPlacementHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
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
        $lane = null;
        $laneHead = false;

        // Every column is read as the board page reads it, so the list order
        // across columns and the counts come from the same query as the page.
        $shownByColumn = [];
        $laneEpics = [];
        foreach ($this->boardColumns->findForProject($command->project) as $boardColumn) {
            $shown = $this->columnCards->shown($boardColumn);
            $shownByColumn[] = [$boardColumn, $shown];
            $counts[(string) $boardColumn->id] = \count($shown);
            if ($boardColumn->terminal) {
                $terminalTotals[(string) $boardColumn->id] = $this->cards->countInColumn($boardColumn);
            }
            foreach ($shown as $card) {
                if ($card->drawsLane()) {
                    $laneEpics[(string) $card->id] = true;
                }
            }
        }

        foreach ($shownByColumn as [$boardColumn, $shown]) {
            $previousInLane = [];
            foreach ($shown as $card) {
                $id = (string) $card->id;
                $cardLane = self::laneOf($card, $laneEpics);
                $isLaneHead = isset($laneEpics[$id]);
                if ($id === $cardId) {
                    [$found, $column, $rowAfter, $lane, $laneHead] = [$card, $boardColumn, $previousRow, $cardLane, $isLaneHead];
                    $after = $isLaneHead ? null : ($previousInLane[$cardLane ?? ''] ?? null);
                }
                if (!$isLaneHead) {
                    $previousInLane[$cardLane ?? ''] = $id;
                }
                $previousRow = $id;
            }
        }

        // The board page draws the lanes in the order sortIntoLanes() finds their epics.
        $laneAfter = null;
        if ($laneHead) {
            $epicIds = array_map(strval(...), array_keys($laneEpics));
            $index = array_search($cardId, $epicIds, true);
            $laneAfter = \is_int($index) && $index > 0 ? $epicIds[$index - 1] : null;
        }

        $pending = null === $found ? 0 : ($this->cardSiteReviewComments->pendingCountsForProject($command->project)[(string) $found->id] ?? 0);

        $progress = null;
        if (CardType::Epic === $found?->type) {
            $children = $this->cards->childProgressForProject($command->project)[(string) $found->id] ?? ['done' => 0, 'total' => 0];
            $progress = new CardProgress($children['done'], $children['total']);
        }

        return new CardPlacementView($found, $column, $after, $rowAfter, $pending, $counts, $terminalTotals, $progress, $lane, $laneHead, $laneAfter);
    }

    /**
     * The lane key the board page gives a card, as ShowBoardHandler sorts it:
     * the id of its lane epic, "other", or null on a board with no lane.
     *
     * @param array<string, true> $laneEpics
     */
    private static function laneOf(Card $card, array $laneEpics): ?string
    {
        if ([] === $laneEpics) {
            return null;
        }
        $parentId = null === $card->parent ? null : (string) $card->parent->id;

        return null !== $parentId && isset($laneEpics[$parentId]) ? $parentId : 'other';
    }
}
