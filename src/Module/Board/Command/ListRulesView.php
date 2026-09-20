<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\View\ReportedRule;
use App\Module\Project\Entity\Project;

final readonly class ListRulesView
{
    /**
     * @param list<ReportedRule>    $rules
     * @param array<string, string> $columnLabels the project's column labels, keyed by slug
     */
    public function __construct(
        public Project $project,
        public array $rules,
        public int $liveCount,
        public array $columnLabels,
        public string $search = '',
    ) {
    }
}
