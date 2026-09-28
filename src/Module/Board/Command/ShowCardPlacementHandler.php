<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardColumnCards;

/** Reads one card and its neighbours, so the cost does not grow with the board. */
final readonly class ShowCardPlacementHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardDocumentRepository $cardDocuments,
    ) {
    }

    public function __invoke(ShowCardPlacementCommand $command): CardPlacementView
    {
        $windowStart = BoardColumnCards::windowStart();
        $columns = $this->boardColumns->findForProject($command->project);

        $stored = $this->columnCards->counts($command->project, $windowStart);
        $counts = [];
        $terminalTotals = [];
        foreach ($columns as $boardColumn) {
            $id = (string) $boardColumn->id;
            $counts[$id] = $stored[$id]['shown'] ?? 0;
            if ($boardColumn->terminal) {
                $terminalTotals[$id] = $stored[$id]['total'] ?? 0;
            }
        }

        $card = $command->card;
        $columnId = null === $card ? null : $this->columnCards->shownColumnId($card, $windowStart);
        $index = null === $columnId ? null : array_find_key($columns, static fn (BoardColumn $column): bool => (string) $column->id === $columnId);
        if (null === $card || null === $index) {
            return new CardPlacementView(null, null, null, null, 0, 0, $counts, $terminalTotals);
        }

        $column = $columns[$index];
        $after = $this->columnCards->previousShown($card, $column, $windowStart);
        $rowAfter = $after;
        for ($earlier = $index - 1; null === $rowAfter && $earlier >= 0; --$earlier) {
            $rowAfter = $this->columnCards->lastShown($columns[$earlier], $windowStart);
        }

        $progress = null;
        if (CardType::Epic === $card->type) {
            $children = $this->cards->childProgressOf($card);
            $progress = new CardProgress($children['done'], $children['total']);
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
        );
    }
}
