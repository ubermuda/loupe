<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\RuleBudgets;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(RuleBudgets::class)]
final readonly class WorkflowRuleBudgets implements RuleBudgets
{
    public function __construct(
        private WorkflowRuleStateRepository $workflowRuleStates,
        private TemplateSource $templates,
    ) {
    }

    #[\Override]
    public function fires(Uuid $cardId, string $ruleId): ?int
    {
        return $this->workflowRuleStates->findOneBy(['cardId' => $cardId, 'ruleId' => $ruleId])?->fires;
    }

    #[\Override]
    public function limit(Uuid $projectId, string $ruleId): ?int
    {
        try {
            $template = $this->templates->forProject($projectId);
        } catch (TemplateMissing) {
            return null;
        }

        foreach ($template->rules as $rule) {
            if ($ruleId === $rule->id) {
                $limit = $rule->then->params['limit'] ?? null;

                return \is_int($limit) ? $limit : null;
            }
        }

        return null;
    }
}
