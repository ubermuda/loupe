<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;

/**
 * A lane is an epic in an open column with its lane on, in board order.
 * Its children fill its row, and every other card goes to the last row.
 */
final readonly class BoardLanes
{
    public const string OTHER = 'other';

    /**
     * @param list<BoardColumnView> $columns
     *
     * @return array{list<BoardLaneView>, ?BoardLaneView}
     */
    public function sort(array $columns): array
    {
        $epics = [];
        foreach ($columns as $view) {
            if ($view->column->terminal) {
                continue;
            }
            foreach ($view->cards as $card) {
                if ($card->drawsLane()) {
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
