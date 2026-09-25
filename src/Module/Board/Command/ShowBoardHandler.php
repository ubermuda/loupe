<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardColumnCards;

final readonly class ShowBoardHandler
{
    public const int TERMINAL_WINDOW_DAYS = BoardColumnCards::TERMINAL_WINDOW_DAYS;

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private BoardColumnCards $columnCards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private BridgeRuleReportRepository $bridgeRuleReports,
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

        return new BoardView(
            $project,
            $columns,
            self::TERMINAL_WINDOW_DAYS,
            // One aggregate for the whole board. A count per card would be a
            // query per card, on the page that renders the most of them.
            $this->cardSiteReviewComments->pendingCountsForProject($project),
            $deadRules,
            array_values(array_unique($watchedSlugs)),
        );
    }
}
