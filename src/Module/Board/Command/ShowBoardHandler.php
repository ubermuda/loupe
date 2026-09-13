<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

final readonly class ShowBoardHandler
{
    /**
     * How far back a terminal column reads.
     *
     * A terminal column only ever grows, so it shows a recent slice and the
     * history page carries the rest.
     */
    public const int TERMINAL_WINDOW_DAYS = 7;

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private BridgeRuleReportRepository $bridgeRuleReports,
    ) {
    }

    public function __invoke(ShowBoardCommand $command): BoardView
    {
        $project = $command->project;
        $columns = [];

        foreach ($this->boardColumns->findForProject($project) as $column) {
            if ($column->terminal) {
                $recent = $this->cards->findCompletedSince(
                    $column,
                    new \DateTimeImmutable(\sprintf('-%d days', self::TERMINAL_WINDOW_DAYS)),
                );

                $columns[] = new BoardColumnView($column, [new BoardGroupView(null, $recent)], \count($recent), $this->cards->countInColumn($column));

                continue;
            }

            // One query per column rather than one per priority group: the read
            // already comes back ordered by priority then position, so the
            // grouping below only has to split a list that is in board order.
            $cards = $this->cards->findForBoard([$column]);

            $groups = [];
            foreach (CardPriority::cases() as $priority) {
                $groups[] = new BoardGroupView($priority, array_values(array_filter(
                    $cards,
                    static fn (Card $card): bool => $card->priority === $priority,
                )));
            }

            $columns[] = new BoardColumnView($column, $groups, \count($cards));
        }

        $deadRules = [];
        $watchedSlugs = [];
        foreach ($this->bridgeRuleReports->findForProject($project) as $report) {
            foreach ($report->rules as $rule) {
                if (BridgeRuleReport::STATE_DEAD === $rule['state']) {
                    $deadRules[] = new DeadBridgeRuleView($rule['name'], $rule['columns'], $rule['reason'] ?? '', $report->receivedAt);

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
