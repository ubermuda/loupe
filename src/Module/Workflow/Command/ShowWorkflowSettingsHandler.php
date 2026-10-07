<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\Not;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\ManualMove;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\Slot;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateSource;

final readonly class ShowWorkflowSettingsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private TemplateSource $templates,
    ) {
    }

    public function __invoke(ShowWorkflowSettingsCommand $command): WorkflowSettingsView
    {
        $project = $command->project;
        $projectId = $project->id ?? throw new \LogicException('The project is not persisted.');
        $binding = $this->workflowBindings->findOneByProjectId($projectId);
        if (null === $binding) {
            return new WorkflowSettingsView($project, null);
        }

        $template = $this->templates->forProject($projectId);
        $rules = static fn (RuleOrigin $origin): array => array_map(
            static fn (Rule $rule): WorkflowRuleView => self::rule($template, $rule),
            array_values(array_filter($template->rules, static fn (Rule $rule): bool => $origin === $rule->origin)),
        );
        $columns = $this->workflowSlotLinks->findColumnsBySlot($project);

        return new WorkflowSettingsView($project, new BoundWorkflowView(
            key: $template->key,
            labelKey: \sprintf('workflow.template.%s.label', $template->key),
            descriptionKey: \sprintf('workflow.template.%s.description', $template->key),
            version: $template->version,
            boundAt: $binding->boundAt,
            slots: array_map(
                static fn (Slot $slot): WorkflowSlotView => new WorkflowSlotView($slot->key, $slot->label, ($columns[$slot->key] ?? null)?->label),
                $template->slots,
            ),
            rules: $rules(RuleOrigin::Template),
            appRules: $rules(RuleOrigin::App),
            manualMoves: array_map(
                static fn (ManualMove $move): WorkflowManualMoveView => new WorkflowManualMoveView(
                    self::placeKey($template, $move->from),
                    self::placeKey($template, $move->to),
                    match ($move->by) {
                        null => 'workflow.settings.moves.by.anyone',
                        ManualMoveActor::ParentRun => 'workflow.settings.moves.by.parent_run',
                    },
                ),
                $template->manualMoves,
            ),
            backoffMinutes: $template->backoffMinutes,
            workTimeoutMinutes: $template->workTimeoutMinutes,
        ));
    }

    private static function rule(Template $template, Rule $rule): WorkflowRuleView
    {
        $params = $rule->then->params;
        $type = $rule->then->type;
        $expressions = array_values(array_filter([$rule->when, $rule->then->until]));
        $missing = array_merge(...array_map(static fn (Expression $expression): array => $expression->missingKeys(), $expressions));

        return new WorkflowRuleView(
            id: $rule->id,
            appliesToKey: self::placeKey($template, $rule->slot ?? '*'),
            actionKey: match ($type) {
                ActionType::Move => 'workflow.settings.action.move',
                ActionType::Request => 'workflow.settings.action.request',
                ActionType::ForgeWrite => 'workflow.settings.action.forge_write',
                ActionType::Pause => 'workflow.settings.action.pause',
                ActionType::Release => 'workflow.settings.action.release',
                ActionType::Evaluate => 'workflow.settings.action.evaluate',
            },
            targetKey: ActionType::Move === $type ? self::placeKey($template, (string) $params['to']) : null,
            kind: match ($type) {
                ActionType::Request => (string) $params['kind'],
                ActionType::ForgeWrite => (string) $params['write'],
                default => null,
            },
            whenGroups: self::groups($rule->when),
            untilGroups: null === $rule->then->until ? [] : self::groups($rule->then->until),
            missingConditions: array_values(array_unique($missing)),
        );
    }

    /** @return list<WorkflowConditionGroupView> */
    private static function groups(Expression $expression): array
    {
        $groups = [];
        foreach (self::conditions($expression, false) as [$leaf, $negated]) {
            $condition = new WorkflowConditionView($leaf->condition::key(), $negated, self::params($leaf->params));
            $groups[$leaf->condition::source()][serialize($condition)] = $condition;
        }

        return array_map(
            static fn (string $source, array $conditions): WorkflowConditionGroupView => new WorkflowConditionGroupView($source, array_values($conditions)),
            array_keys($groups),
            array_values($groups),
        );
    }

    /**
     * A missing condition yields nothing here, because missingKeys() lists it.
     *
     * @return list<array{ConditionLeaf, bool}> each leaf with whether a `not` above it negates it, in template order
     */
    private static function conditions(Expression $expression, bool $negated): array
    {
        return match (true) {
            $expression instanceof ConditionLeaf => [[$expression, $negated]],
            $expression instanceof Not => self::conditions($expression->inner, !$negated),
            $expression instanceof AllOf, $expression instanceof AnyOf => array_merge(...array_map(static fn (Expression $child): array => self::conditions($child, $negated), $expression->children)),
            $expression instanceof MissingConditionLeaf => [],
            default => throw new \LogicException(\sprintf('The settings page cannot list a %s.', $expression::class)),
        };
    }

    /** @param array<string, mixed> $params */
    private static function params(array $params): string
    {
        return implode(', ', array_map(
            static fn (string $name, mixed $value): string => $name.': '.(\is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            array_keys($params),
            array_values($params),
        ));
    }

    /** @param string $place a slot key, '@backlog', '@terminal' or '*' */
    private static function placeKey(Template $template, string $place): string
    {
        return match ($place) {
            '@backlog' => 'workflow.settings.where.backlog',
            '@terminal' => 'workflow.settings.where.any_terminal',
            '*' => 'workflow.settings.where.any',
            default => ($template->slot($place) ?? throw new \LogicException(\sprintf('The template has no slot "%s".', $place)))->label,
        };
    }
}
