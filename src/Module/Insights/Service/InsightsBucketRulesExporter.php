<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;

/** The time bucket rules of the projects the user owns. */
final readonly class InsightsBucketRulesExporter implements UserDataExporterInterface
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'insights_bucket_rules.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->insightsBucketRules->findByOwner($user) as $rule) {
            yield [
                'projectId' => (string) $rule->project->id,
                'project' => $rule->project->name,
                'position' => $rule->position,
                'pattern' => $rule->pattern,
                'bucket' => $rule->bucket,
            ];
        }
    }
}
