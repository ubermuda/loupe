<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

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

        // Every column is read as the board page reads it, so the list order
        // across columns and the counts come from the same query as the page.
        foreach ($this->boardColumns->findForProject($command->project) as $boardColumn) {
            $shown = $this->columnCards->shown($boardColumn);
            $counts[(string) $boardColumn->id] = \count($shown);
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

        $pending = null === $found ? 0 : ($this->cardSiteReviewComments->pendingCountsForProject($command->project)[(string) $found->id] ?? 0);

        return new CardPlacementView($found, $column, $after, $rowAfter, $pending, $counts, $terminalTotals);
    }
}
