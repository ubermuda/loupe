<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\View\ReportedRule;

final readonly class ListRulesHandler
{
    public function __construct(
        private BridgeRuleReportRepository $bridgeRuleReports,
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
            $search,
        );
    }
}
