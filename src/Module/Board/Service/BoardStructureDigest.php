<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;
use App\Module\Board\Command\CardProgress;

/**
 * A short hash of the columns and the lanes the board page draws, so a page
 * can tell that its frame changed and a card-by-card refresh is not enough.
 */
final readonly class BoardStructureDigest
{
    /**
     * @param list<BoardColumnView>       $columns
     * @param list<BoardLaneView>         $lanes
     * @param array<string, CardProgress> $progress epic id => its progress
     */
    public function forBoard(array $columns, array $lanes, array $progress): string
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
            $epicProgress = $progress[(string) $epic->id] ?? null;
            $shape['lanes'][] = [(string) $epic->id, $epic->number, $epic->title, $epic->laneEnabled, $epicProgress?->done, $epicProgress?->total];
        }

        return substr(sha1(json_encode($shape, \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
