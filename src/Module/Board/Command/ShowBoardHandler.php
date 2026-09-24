<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Entity\CardType;
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

                $columns[] = new BoardColumnView($column, $recent, \count($recent), $this->cards->countInColumn($column));

                continue;
            }

            $cards = $this->cards->findForBoard([$column]);
            $columns[] = new BoardColumnView($column, $cards, \count($cards));
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

        [$lanes, $otherCards] = self::sortIntoLanes($columns);

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

        return new BoardView(
            $project,
            $columns,
            self::TERMINAL_WINDOW_DAYS,
            // One aggregate for the whole board. A count per card would be a
            // query per card, on the page that renders the most of them.
            $this->cardSiteReviewComments->pendingCountsForProject($project),
            $deadRules,
            array_values(array_unique($watchedSlugs)),
            $lanes,
            $otherCards,
            $progress,
            $shownCounts,
        );
    }

    /**
     * A lane is an epic in an open column with its lane on, in board order.
     * Its children fill its row, and every other card goes to the last row.
     *
     * @param list<BoardColumnView> $columns
     *
     * @return array{list<BoardLaneView>, ?BoardLaneView}
     */
    private static function sortIntoLanes(array $columns): array
    {
        $epics = [];
        foreach ($columns as $view) {
            if ($view->column->terminal) {
                continue;
            }
            foreach ($view->cards as $card) {
                if (CardType::Epic === $card->type && $card->laneEnabled) {
                    $epics[(string) $card->id] = $card;
                }
            }
        }

        if ([] === $epics) {
            return [[], null];
        }

        $cells = array_fill_keys(array_keys($epics), []);
        $other = [];
        foreach ($columns as $view) {
            $columnId = (string) $view->column->id;
            foreach ($view->cards as $card) {
                if (isset($epics[(string) $card->id])) {
                    continue;
                }
                $parentId = null === $card->parent ? null : (string) $card->parent->id;
                if (null !== $parentId && isset($epics[$parentId])) {
                    $cells[$parentId][$columnId][] = $card;
                } else {
                    $other[$columnId][] = $card;
                }
            }
        }

        $lanes = [];
        foreach ($epics as $epicId => $epic) {
            $lanes[] = new BoardLaneView($epic, $cells[$epicId]);
        }

        return [$lanes, new BoardLaneView(null, $other)];
    }
}
