<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\ColumnView;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;

final readonly class ShowWorkflowHandler
{
    public function __construct(
        private TemplateSource $templates,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private BoardColumns $boardColumns,
        private AppRules $appRules,
    ) {
    }

    /** @throws TemplateMissing when the project has no workflow */
    public function __invoke(ShowWorkflowCommand $command): WorkflowView
    {
        $project = $command->project;
        $template = $this->templates->forProject($project->id ?? throw new \LogicException('The project is not persisted.'));

        $slots = [];
        foreach ($this->workflowSlotLinks->findColumnsBySlot($project) as $slot => $column) {
            if (null !== $column) {
                $slots[(string) $column->id] = $slot;
            }
        }

        $kinds = [];
        foreach ([RuleOrigin::Template, RuleOrigin::App] as $origin) {
            foreach ($template->rules as $rule) {
                $kind = $origin === $rule->origin ? self::kind($rule) : null;
                if (null === $kind) {
                    continue;
                }
                $kinds[$kind] ??= ['origin' => $origin, 'rules' => [], 'checks' => []];
                $kinds[$kind]['rules'][] = $rule->id;
                $kinds[$kind]['checks'] = array_values(array_unique([...$kinds[$kind]['checks'], ...$rule->then->checks]));
            }
        }

        foreach ($this->appRules->requests() as $request) {
            $kinds[$request->kind] ??= ['origin' => RuleOrigin::App, 'rules' => [], 'checks' => []];
            $kinds[$request->kind]['rules'][] = $request->id;
            $kinds[$request->kind]['checks'] = array_values(array_unique([...$kinds[$request->kind]['checks'], ...$request->checks]));
        }

        return new WorkflowView(
            templateKey: $template->key,
            templateVersion: $template->version,
            columns: array_map(
                static fn (ColumnView $column): WorkflowColumnView => new WorkflowColumnView($column, $slots[(string) $column->id] ?? null),
                $this->boardColumns->forProject($project->id ?? throw new \LogicException('The project is not persisted.')),
            ),
            kinds: array_map(
                static fn (string $kind, array $entry): WorkflowKindView => new WorkflowKindView($kind, $entry['origin'], $entry['rules'], $entry['checks']),
                array_keys($kinds),
                array_values($kinds),
            ),
        );
    }

    /** The kind of work a rule asks a bridge for: a request's kind, or a write's fallback. */
    private static function kind(Rule $rule): ?string
    {
        $params = $rule->then->params;

        return match ($rule->then->type) {
            ActionType::Request => (string) $params['kind'],
            ActionType::ForgeWrite => isset($params['fallback']) ? (string) $params['fallback'] : null,
            default => null,
        };
    }
}
