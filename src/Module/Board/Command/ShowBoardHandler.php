<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardColumnCards;
use App\Module\Board\Service\BoardLanes;
use App\Module\Board\Service\BoardStructureDigest;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\LaneDecks;
use App\Module\Board\Service\RacingBridgeRules;
use App\Module\Bridge\Service\CardRunWarnings;

final readonly class ShowBoardHandler
{
    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private BridgeRuleReportRepository $bridgeRuleReports,
        private CardDocumentRepository $cardDocuments,
        private BoardLanes $boardLanes,
        private BoardStructureDigest $structureDigest,
        private CardRunWarnings $runWarnings,
        private LaneDecks $laneDecks,
        private CardPullRequestStates $pullRequestStates,
        private RacingBridgeRules $racingRules,
        private BoardAutomation $automation,
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

        $deadRules = [];
        $watchedSlugs = [];
        $reports = $this->bridgeRuleReports->findForProject($project);
        $racingRules = $this->racingRules->forProject($project, $reports);
        foreach ($reports as $report) {
            foreach ($report->rules as $rule) {
                if (BridgeRuleReport::STATE_DEAD === $rule['state']) {
                    $deadRules[] = new DeadBridgeRuleView($rule['name'], $rule['columns'], $rule['reason'] ?? '', $report->receivedAt, (string) $report->bridgeId);

                    continue;
                }

                array_push($watchedSlugs, ...$rule['columns']);
            }
        }

        $backlog ??= throw new \LogicException('Every board has a Backlog.');
        [$lanes, $otherCards] = $this->boardLanes->sort($columns, $this->cards->findLaneEpics($project));

        $counts = $this->cards->childProgressForProject($project);
        $progress = [];
        $shownCounts = [];
        foreach ($columns as $view) {
            $columnId = (string) $view->column->id;
            $shownCounts[$columnId] = null === $otherCards
                ? $view->count
                : array_sum(array_map(static fn (BoardLaneView $lane): int => \count($lane->cells[$columnId] ?? []), [...$lanes, $otherCards]));
            foreach ($view->cards as $card) {
                if (CardType::Epic === $card->type) {
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
        $states = $this->pullRequestStates->forCards($shownCards);
        $badges = [];
        foreach ($shownCards as $card) {
            $cardBadges = $states->badgesOf($card);
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
            $deadRules,
            array_values(array_unique($watchedSlugs)),
            $lanes,
            $otherCards,
            $progress,
            $shownCounts,
            $this->structureDigest->forBoard($columns, $lanes, $deadRules, $racingRules),
            $runWarnings,
            $this->laneDecks->forEpics($backlog, array_map(static fn (BoardLaneView $lane): string => (string) $lane->epic?->id, $lanes)),
            $badges,
            $racingRules,
        );
    }
}
