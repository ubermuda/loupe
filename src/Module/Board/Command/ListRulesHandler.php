<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\View\ReportedRule;

final readonly class ListRulesHandler
{
    public function __construct(
        private BridgeRuleReportRepository $bridgeRuleReports,
        private BoardColumnRepository $boardColumns,
    ) {
    }

    public function __invoke(ListRulesCommand $command): ListRulesView
    {
        $rules = [];
        foreach ($this->bridgeRuleReports->findForProject($command->project) as $report) {
            foreach ($report->rules as $rule) {
                $rules[] = new ReportedRule(
                    $report->bridgeId,
                    $rule['name'],
                    $rule['on'],
                    $rule['columns'],
                    $rule['state'],
                    $rule['reason'],
                    $report->receivedAt,
                );
            }
        }

        $search = trim($command->search);

        return new ListRulesView(
            $command->project,
            array_values(array_filter($rules, static fn (ReportedRule $rule): bool => '' === $search || false !== mb_stripos($rule->name, $search))),
            count(array_filter($rules, static fn (ReportedRule $rule): bool => BridgeRuleReport::STATE_LIVE === $rule->state)),
            array_column(array_map(static fn (BoardColumn $column): array => [$column->slug, $column->label], $this->boardColumns->findForProject($command->project)), 1, 0),
            $search,
        );
    }
}
