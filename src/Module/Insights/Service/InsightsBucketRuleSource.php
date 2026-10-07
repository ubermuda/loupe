<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Service\BucketRule;
use App\Module\Bridge\Service\BucketRuleSourceInterface;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Project\Entity\Project;

/** The bucket rules a project sets on the Time buckets page. */
final readonly class InsightsBucketRuleSource implements BucketRuleSourceInterface
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    #[\Override]
    public function rulesFor(Project $project): array
    {
        return array_map(
            static fn ($rule): BucketRule => new BucketRule($rule->pattern, $rule->bucket),
            $this->insightsBucketRules->findOrdered($project),
        );
    }
}
