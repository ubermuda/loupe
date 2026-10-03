<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;

/**
 * A short hash of the columns, the lanes and the terminal window the board
 * page draws, so a page can tell that its frame changed and a card-by-card
 * refresh is not enough. A lane head is placed like a card,
 * so its face is left out.
 */
final readonly class BoardStructureDigest
{
    /**
     * @param list<BoardColumnView> $columns
     * @param list<BoardLaneView>   $lanes
     */
    public function forBoard(array $columns, array $lanes, int $terminalWindowDays): string
    {
        $shape = ['columns' => [], 'lanes' => [], 'terminalWindowDays' => $terminalWindowDays];
        foreach ($columns as $view) {
            $column = $view->column;
            $shape['columns'][] = [(string) $column->id, $column->label, $column->tone->value, $column->terminal];
        }
        foreach ($lanes as $lane) {
            $epic = $lane->epic;
            if (null === $epic) {
                continue;
            }
            $shape['lanes'][] = [(string) $epic->id, $epic->laneEnabled];
        }

        return substr(sha1(json_encode($shape, \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
