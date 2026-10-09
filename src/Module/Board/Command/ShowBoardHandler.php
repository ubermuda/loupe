<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardColumnCards;
use App\Module\Board\Service\BoardLanes;
use App\Module\Board\Service\BoardStructureDigest;
use App\Module\Board\Service\CardMarkers;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\CardStates;
use App\Module\Board\Service\LaneDecks;
use App\Module\Bridge\Service\CardRunWarnings;
use App\Module\Workflow\Contract\CardTypeCatalog;

final readonly class ShowBoardHandler
{
    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardDocumentRepository $cardDocuments,
        private BoardLanes $boardLanes,
        private BoardStructureDigest $structureDigest,
        private CardRunWarnings $runWarnings,
        private LaneDecks $laneDecks,
        private CardPullRequestStates $pullRequestStates,
        private CardMarkers $markers,
        private BoardAutomation $automation,
        private CardTypeCatalog $catalog,
        private CardStates $cardStates,
        private CardPauseRepository $cardPauses,
    ) {
    }

    public function __invoke(ShowBoardCommand $command): BoardView
    {
        $project = $command->project;
        $columns = [];
        $backlog = null;
        $terminalWindowDays = $this->automation->settingsOf($project)->terminalWindowDays;
        $windowStart = BoardColumnCards::windowStart($terminalWindowDays);

        foreach ($this->boardColumns->findForProject($project) as $column) {
            if ($column->backlog) {
                $backlog = $column;
                continue;
            }
            $shown = $this->columnCards->shown($column, $windowStart);
            $columns[] = new BoardColumnView(
                $column,
                $shown,
                \count($shown),
                $column->terminal ? $this->cards->countInColumn($column) : null,
            );
        }

        $backlog ??= throw new \LogicException('Every board has a Backlog.');
        $types = $this->catalog->forProject($project->requireId());
        [$lanes, $otherCards] = $this->boardLanes->sort($columns, $this->cards->findLaneEpics($project, $types->withLane()));

        $counts = $this->cards->childProgressForProject($project);
        $progress = [];
        $shownCounts = [];
        foreach ($columns as $view) {
            $columnId = (string) $view->column->id;
            $shownCounts[$columnId] = null === $otherCards
                ? $view->count
                : array_sum(array_map(static fn (BoardLaneView $lane): int => \count($lane->cells[$columnId] ?? []), [...$lanes, $otherCards]));
            foreach ($view->cards as $card) {
                if ($types->get($card->type)->children) {
                    $epicCounts = $counts[(string) $card->id] ?? ['done' => 0, 'total' => 0];
                    $progress[(string) $card->id] = new CardProgress($epicCounts['done'], $epicCounts['total']);
                }
            }
        }
        // A lane epic in the Backlog has a lane head and no card in any column.
        foreach ($lanes as $lane) {
            $epicId = (string) $lane->epic?->id;
            $epicCounts = $counts[$epicId] ?? ['done' => 0, 'total' => 0];
            $progress[$epicId] ??= new CardProgress($epicCounts['done'], $epicCounts['total']);
        }

        // One aggregate each for the whole board. A count per card would be
        // a query per card, on the page that renders the most of them.
        $pendingComments = $this->cardSiteReviewComments->pendingCountsForProject($project);
        $documentCounts = $this->cardDocuments->countsForProject($project);
        $runWarnings = $this->runWarnings->forProject($project);
        $shownCards = array_merge(...array_map(static fn (BoardColumnView $view): array => $view->cards, $columns));
        $pullRequestStates = $this->pullRequestStates->forCards($shownCards);
        $paused = $this->cardPauses->findActiveForCardIds(array_map(static fn (Card $card): string => (string) $card->id, $shownCards));
        $markers = $this->markers->forCards($project, $shownCards, $paused);
        $badges = [];
        foreach ($shownCards as $card) {
            $cardBadges = [...$pullRequestStates->badgesOf($card), ...($markers[(string) $card->id] ?? [])];
            if ([] !== $cardBadges) {
                $badges[(string) $card->id] = $cardBadges;
            }
        }

        return new BoardView(
            $project,
            $columns,
            $terminalWindowDays,
            $backlog,
            $this->cards->countInColumn($backlog),
            $pendingComments,
            $documentCounts,
            $lanes,
            $otherCards,
            $progress,
            $shownCounts,
            $this->structureDigest->forBoard($columns, $lanes, $terminalWindowDays),
            $runWarnings,
            $this->laneDecks->forEpics($backlog, array_map(static fn (BoardLaneView $lane): string => (string) $lane->epic?->id, $lanes)),
            $badges,
            $this->cardStates->forCards($project, $shownCards, $pullRequestStates, $runWarnings, $paused),
        );
    }
}
