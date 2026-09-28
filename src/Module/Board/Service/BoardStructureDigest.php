<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;
use App\Module\Board\Command\DeadBridgeRuleView;

/**
 * A short hash of the columns, the lanes and the dead bridge rules the board
 * page draws, so a page can tell that its frame changed and a card-by-card
 * refresh is not enough. A lane head is placed like a card, so its face is
 * left out.
 */
final readonly class BoardStructureDigest
{
    /**
     * @param list<BoardColumnView>    $columns
     * @param list<BoardLaneView>      $lanes
     * @param list<DeadBridgeRuleView> $deadRules
     */
    public function forBoard(array $columns, array $lanes, array $deadRules = []): string
    {
        $shape = ['columns' => [], 'lanes' => [], 'deadRules' => []];
        foreach ($columns as $view) {
            $column = $view->column;
            $shape['columns'][] = [(string) $column->id, $column->label, $column->tone->value, $column->terminal, $column->isDefault];
        }
        foreach ($lanes as $lane) {
            $epic = $lane->epic;
            if (null === $epic) {
                continue;
            }
            $shape['lanes'][] = [(string) $epic->id, $epic->laneEnabled];
        }

        foreach ($deadRules as $rule) {
            $shape['deadRules'][] = [$rule->name, $rule->columns, $rule->reason, $rule->reportedAt->format('c'), $rule->bridgeId];
        }

        return substr(sha1(json_encode($shape, \JSON_THROW_ON_ERROR)), 0, 12);
    }
}
