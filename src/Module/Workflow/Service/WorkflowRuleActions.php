<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\BoardSettings;
use App\Module\Workflow\Contract\RuleActions;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(RuleActions::class)]
final readonly class WorkflowRuleActions implements RuleActions
{
    public function __construct(
        private BoardSettings $boardSettings,
        private TemplateSource $templates,
    ) {
    }

    #[\Override]
    public function onCondition(Uuid $projectId, string $conditionKey, string $actionKey): array
    {
        if (!$this->boardSettings->automationEnabled($projectId)) {
            return [];
        }

        try {
            $template = $this->templates->forProject($projectId);
        } catch (TemplateMissing) {
            return [];
        }

        $params = [];
        foreach ($template->rules as $rule) {
            if ($actionKey === $rule->then->key && self::firesOn($rule->when, $conditionKey)) {
                $params[] = $rule->then->params;
            }
        }

        return $params;
    }

    private static function firesOn(Expression $when, string $conditionKey): bool
    {
        $leaves = $when instanceof AllOf ? $when->children : [$when];

        return array_any($leaves, static fn (Expression $leaf): bool => $leaf instanceof ConditionLeaf && $conditionKey === $leaf->condition::key());
    }
}
