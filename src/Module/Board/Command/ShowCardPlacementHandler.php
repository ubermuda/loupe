<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardColumnCards;
use App\Module\Board\Service\BoardLanes;
use App\Module\Board\Service\CardMarkers;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\LaneDecks;
use App\Module\Workflow\Contract\CardTypeCatalog;

/** Reads one card and its neighbours, so the cost does not grow with the board. */
final readonly class ShowCardPlacementHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardDocumentRepository $cardDocuments,
        private LaneDecks $laneDecks,
        private CardPullRequestStates $pullRequestStates,
        private CardMarkers $markers,
        private BoardAutomation $automation,
        private CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(ShowCardPlacementCommand $command): CardPlacementView
    {
        $windowStart = BoardColumnCards::windowStart($this->automation->settingsOf($command->project)->terminalWindowDays);
        $columns = [];
        $backlog = null;
        foreach ($this->boardColumns->findForProject($command->project) as $boardColumn) {
            if ($boardColumn->backlog) {
                $backlog = $boardColumn;
            } else {
                $columns[] = $boardColumn;
            }
        }
        $backlog ??= throw new \LogicException('Every board has a Backlog.');

        $stored = $this->columnCards->counts($command->project, $windowStart);
        $counts = [(string) $backlog->id => $stored[(string) $backlog->id]['shown'] ?? 0];
        $terminalTotals = [];
        foreach ($columns as $boardColumn) {
            $id = (string) $boardColumn->id;
            $counts[$id] = $stored[$id]['shown'] ?? 0;
            if ($boardColumn->terminal) {
                $terminalTotals[$id] = $stored[$id]['total'] ?? 0;
            }
        }

        $card = $command->card;
        $types = $this->catalog->forProject($command->project->requireId());
        $columnId = null === $card ? null : $this->columnCards->shownColumnId($card, $windowStart);
        $laneEpicIds = null === $columnId ? [] : array_map(
            static fn (Card $epic): string => (string) $epic->id,
            $this->cards->findLaneEpics($command->project, $types->withLane()),
        );
        $laneIndex = null === $card ? false : array_search((string) $card->id, $laneEpicIds, true);
        $laneHead = \is_int($laneIndex);
        $laneAfter = $laneHead && $laneIndex > 0 ? $laneEpicIds[$laneIndex - 1] : null;

        // The Backlog is no column of the board, and a lane epic there still heads its lane.
        $index = null === $columnId ? null : array_find_key($columns, static fn (BoardColumn $column): bool => (string) $column->id === $columnId);
        $inBacklog = $laneHead && (string) $backlog->id === $columnId;
        if (null === $card || (null === $index && !$inBacklog)) {
            $parentId = null === $card?->parent ? null : (string) $card->parent->id;
            $deckEpic = (string) $backlog->id === $columnId && \in_array($parentId, $laneEpicIds, true) ? $parentId : null;

            return new CardPlacementView(null, null, null, null, 0, 0, $counts, $terminalTotals, null, null, false, null, deckEpic: $deckEpic);
        }

        $progress = null;
        if ($types->get($card->type)->children) {
            $children = $this->cards->childProgressOf($card);
            $progress = new CardProgress($children['done'], $children['total']);
        }

        $column = $backlog;
        $after = $rowAfter = $lane = null;
        if (null !== $index) {
            $column = $columns[$index];
            $previous = $this->columnCards->previousShown($card, $column, $windowStart);
            $rowAfter = $previous;
            for ($earlier = $index - 1; null === $rowAfter && $earlier >= 0; --$earlier) {
                $rowAfter = $this->columnCards->lastShown($columns[$earlier], $windowStart);
            }

            if ($laneHead) {
                $after = null;
            } elseif ([] === $laneEpicIds) {
                $after = $previous;
            } else {
                $parentId = null === $card->parent ? null : (string) $card->parent->id;
                $lane = null !== $parentId && \in_array($parentId, $laneEpicIds, true) ? $parentId : BoardLanes::OTHER;
                $after = $this->columnCards->previousShown($card, $column, $windowStart, $lane, $laneEpicIds);
            }
        }

        return new CardPlacementView(
            $card,
            $column,
            $after,
            $rowAfter,
            $this->cardSiteReviewComments->pendingCountForCard($card),
            $this->cardDocuments->countForCard($card),
            $counts,
            $terminalTotals,
            $progress,
            $lane,
            $laneHead,
            $laneAfter,
            $laneHead ? ($this->laneDecks->forEpics($backlog, [(string) $card->id])[(string) $card->id] ?? null) : null,
            [...$this->pullRequestStates->forCards([$card])->badgesOf($card), ...($this->markers->forCards($card->project, [$card])[(string) $card->id] ?? [])],
        );
    }
}
