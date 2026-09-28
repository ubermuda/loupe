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
use App\Module\Board\Service\BoardColumnCards;
use App\Module\Board\Service\BoardLanes;
use App\Module\Board\Service\BoardStructureDigest;
use App\Module\Board\Service\CardDigest;
use App\Module\Bridge\Service\CardRunWarnings;

final readonly class ShowBoardHandler
{
    public const int TERMINAL_WINDOW_DAYS = BoardColumnCards::TERMINAL_WINDOW_DAYS;

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private BridgeRuleReportRepository $bridgeRuleReports,
        private CardDocumentRepository $cardDocuments,
        private BoardLanes $boardLanes,
        private BoardStructureDigest $structureDigest,
        private CardDigest $cardDigest,
        private CardRunWarnings $runWarnings,
    ) {
    }

    public function __invoke(ShowBoardCommand $command): BoardView
    {
        $project = $command->project;
        $columns = [];

        foreach ($this->boardColumns->findForProject($project) as $column) {
            $shown = $this->columnCards->shown($column);
            $columns[] = new BoardColumnView(
                $column,
                $shown,
                \count($shown),
                $column->terminal ? $this->cards->countInColumn($column) : null,
            );
        }

        $deadRules = [];
        $watchedSlugs = [];
        foreach ($this->bridgeRuleReports->findForProject($project) as $report) {
            foreach ($report->rules as $rule) {
                if (BridgeRuleReport::STATE_DEAD === $rule['state']) {
                    $deadRules[] = new DeadBridgeRuleView($rule['name'], $rule['columns'], $rule['reason'] ?? '', $report->receivedAt, (string) $report->bridgeId);

                    continue;
                }

                array_push($watchedSlugs, ...$rule['columns']);
            }
        }

        [$lanes, $otherCards] = $this->boardLanes->sort($columns);

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

        // One aggregate each for the whole board. A count per card would be
        // a query per card, on the page that renders the most of them.
        $pendingComments = $this->cardSiteReviewComments->pendingCountsForProject($project);
        $documentCounts = $this->cardDocuments->countsForProject($project);
        $runWarnings = $this->runWarnings->forProject($project);

        $epicDigests = [];
        foreach ($lanes as $lane) {
            if (null !== $lane->epic) {
                $id = (string) $lane->epic->id;
                $epicDigests[$id] = $this->cardDigest->forCard(
                    $lane->epic,
                    $pendingComments[$id] ?? 0,
                    $documentCounts[$id] ?? 0,
                    $lane->epic->pullRequests->count(),
                    $progress[$id] ?? null,
                    $runWarnings[$id] ?? null,
                );
            }
        }

        return new BoardView(
            $project,
            $columns,
            self::TERMINAL_WINDOW_DAYS,
            $pendingComments,
            $documentCounts,
            $deadRules,
            array_values(array_unique($watchedSlugs)),
            $lanes,
            $otherCards,
            $progress,
            $shownCounts,
            $this->structureDigest->forBoard($columns, $lanes, $epicDigests),
            $runWarnings,
        );
    }
}
