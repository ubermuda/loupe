<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\View\CardCost;
use App\Module\Bridge\View\CostChart;
use App\Module\Bridge\View\WorkerRunCostQuery;
use App\Module\Project\Entity\Project;

final readonly class WorkerRunCostView
{
    /**
     * @param list<CardCost> $cards  oldest completion first
     * @param list<string>   $rules  every rule with usage in the project
     * @param list<string>   $models every model with usage in the project
     */
    public function __construct(
        public Project $project,
        public WorkerRunCostQuery $query,
        public array $cards,
        public array $rules,
        public array $models,
        /** Millionths of a dollar. */
        public int $totalMicros,
        /** Millionths of a dollar. */
        public int $medianMicros,
        /** Null when no card has usage. */
        public ?CostChart $chart,
    ) {
    }
}
