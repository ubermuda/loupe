<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateParser;

/** Builds what an action reads when it runs, with the column of each slot parameter that has one. */
final readonly class ActionContexts
{
    public function __construct(
        private Actions $actions,
        private ProjectRepository $projects,
        private BoardColumns $boardColumns,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private AppRules $appRules,
    ) {
    }

    public function for(Rule $rule, CardSnapshot $card, Facts $facts, int $fires): ActionContext
    {
        $name = RuleOrigin::App === $rule->origin ? $rule->then->params[TemplateParser::PROMPT] ?? null : null;

        return $rule->context($card, $facts, $fires, $this->columns($rule, $card), \is_string($name) ? $this->appRules->prompt($name) : null);
    }

    /** @return array<string, ColumnRef> a slot with no column stays out */
    private function columns(Rule $rule, CardSnapshot $card): array
    {
        $slots = [];
        foreach ($this->actions->get($rule->then->key)::parameters() as $parameter) {
            $slot = $rule->then->params[$parameter->name] ?? null;
            if (ParameterType::Slot === $parameter->type && \is_string($slot)) {
                $slots[$parameter->name] = $slot;
            }
        }
        if ([] === $slots) {
            return [];
        }

        $project = $this->projects->find($card->projectId) ?? throw new \LogicException('A stored card has a project.');
        $linked = $this->workflowSlotLinks->findColumnsBySlot($project);
        $columns = [];
        foreach ($slots as $name => $slot) {
            $column = match ($slot) {
                SlotKeys::BACKLOG => array_find($this->boardColumns->forProject($card->projectId), static fn ($view): bool => $view->backlog),
                SlotKeys::TERMINAL => array_find($this->boardColumns->forProject($card->projectId), static fn ($view): bool => $view->terminal),
                default => $linked[$slot] ?? null,
            };
            if (null !== $column) {
                $columns[$name] = $column->ref();
            }
        }

        return $columns;
    }
}
