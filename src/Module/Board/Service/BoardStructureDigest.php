<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;

/**
 * A short hash of the columns and the lanes the board page draws, so a page
 * can tell that its frame changed and a card-by-card refresh is not enough.
 */
final readonly class BoardStructureDigest
{
    /**
     * @param list<BoardColumnView> $columns
     * @param list<BoardLaneView>   $lanes
     * @param array<string, string> $epicDigests lane epic id => its card digest, which the manifest leaves out
     */
    public function forBoard(array $columns, array $lanes, array $epicDigests): string
    {
        $shape = ['columns' => [], 'lanes' => []];
        foreach ($columns as $view) {
            $column = $view->column;
            $shape['columns'][] = [(string) $column->id, $column->label, $column->tone->value, $column->terminal, $column->isDefault];
        }
        foreach ($lanes as $lane) {
            $epic = $lane->epic;
            if (null === $epic) {
                continue;
            }
            $shape['lanes'][] = [(string) $epic->id, $epic->laneEnabled, $epicDigests[(string) $epic->id] ?? null];
        }

        return substr(sha1(json_encode($shape, \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
