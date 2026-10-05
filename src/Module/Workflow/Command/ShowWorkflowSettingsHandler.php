<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\ManualMove;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Slot;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;

final readonly class ShowWorkflowSettingsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private TemplateParser $parser,
    ) {
    }

    public function __invoke(ShowWorkflowSettingsCommand $command): WorkflowSettingsView
    {
        $project = $command->project;
        $binding = $this->workflowBindings->findOneByProjectId($project->id ?? throw new \LogicException('The project is not persisted.'));
        if (null === $binding) {
            return new WorkflowSettingsView($project, null);
        }

        $template = $this->parser->parseStored($binding->definition);
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
            rules: array_map(static fn (Rule $rule): WorkflowRuleView => self::rule($template, $rule), $template->rules),
            manualMoves: array_map(
                static fn (ManualMove $move): WorkflowManualMoveView => new WorkflowManualMoveView(self::placeKey($template, $move->from), self::placeKey($template, $move->to)),
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

        $groups = [];
        foreach ($expressions as $expression) {
            foreach ($expression->leaves() as $leaf) {
                $groups[$leaf->condition::source()][$leaf->condition::key()] = true;
            }
        }
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
            },
            targetKey: ActionType::Move === $type ? self::placeKey($template, (string) $params['to']) : null,
            kind: match ($type) {
                ActionType::Request => (string) $params['kind'],
                ActionType::ForgeWrite => (string) $params['write'],
                default => null,
            },
            conditionGroups: array_map(
                static fn (string $source, array $keys): WorkflowConditionGroupView => new WorkflowConditionGroupView($source, array_keys($keys)),
                array_keys($groups),
                array_values($groups),
            ),
            missingConditions: array_values(array_unique($missing)),
        );
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
