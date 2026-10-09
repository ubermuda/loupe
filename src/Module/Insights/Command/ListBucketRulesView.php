<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Project\Entity\Project;

/** The bucket rules of a project in the order they apply. */
final readonly class ListBucketRulesView
{
    public function __construct(
        public Project $project,
        /** @var list<InsightsBucketRule> */
        public array $rules,
        public bool $atLimit,
    ) {
    }
}
