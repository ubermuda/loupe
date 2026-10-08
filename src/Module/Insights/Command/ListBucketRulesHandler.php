<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;

final readonly class ListBucketRulesHandler
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    public function __invoke(ListBucketRulesCommand $command): ListBucketRulesView
    {
        $rules = $this->insightsBucketRules->findOrdered($command->project);

        return new ListBucketRulesView($command->project, $rules, \count($rules) >= InsightsBucketRule::MAX_PER_PROJECT);
    }
}
