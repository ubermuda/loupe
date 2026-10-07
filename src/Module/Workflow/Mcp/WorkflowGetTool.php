<?php

declare(strict_types=1);

namespace App\Module\Workflow\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Workflow\Command\ShowWorkflowCommand;
use App\Module\Workflow\Command\ShowWorkflowHandler;
use App\Module\Workflow\Command\WorkflowColumnView;
use App\Module\Workflow\Command\WorkflowKindView;
use App\Module\Workflow\Template\TemplateMissing;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[McpTool(name: self::NAME, description: 'Read the workflow of this project. template is the key and the version of the workflow template. columns lists the board columns in board order, each with the key of its workflow slot, or null when no slot links to it. kinds lists each kind of work the workflow can ask a bridge for, with the ids of the rules that ask for it and whether the template or the app adds it. checks names what that work needs from the project. A discovery run reads checks to know what to look for before it calls readiness_report_submit.')]
final readonly class WorkflowGetTool
{
    use ResolvesBoundProject;

    public const string NAME = 'workflow_get';

    public function __construct(
        private ShowWorkflowHandler $showWorkflow,
        private AuthenticatedProjectResolver $projectResolver,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{
     *     template: array{key: string, version: int},
     *     columns: list<array{slug: string, label: string, slot: string|null, backlog: bool, terminal: bool}>,
     *     kinds: list<array{kind: string, origin: string, rules: list<string>, checks: list<string>}>,
     * }
     */
    public function __invoke(): array
    {
        try {
            $view = ($this->showWorkflow)(new ShowWorkflowCommand($this->requireBoundProject($this->projectResolver)));

            return [
                'template' => ['key' => $view->templateKey, 'version' => $view->templateVersion],
                'columns' => array_map(fn (WorkflowColumnView $column): array => [
                    'slug' => $column->column->slug,
                    // A seeded label is a translation key, and a renamed one is text that no key matches.
                    'label' => $this->translator->trans($column->column->label),
                    'slot' => $column->slot,
                    'backlog' => $column->column->backlog,
                    'terminal' => $column->column->terminal,
                ], $view->columns),
                'kinds' => array_map(static fn (WorkflowKindView $kind): array => [
                    'kind' => $kind->kind,
                    'origin' => $kind->origin->value,
                    'rules' => $kind->rules,
                    'checks' => $kind->checks,
                ], $view->kinds),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (TemplateMissing $e) {
            throw new ToolCallException('This project has no workflow yet. The owner picks a workflow template when they create the project.', previous: $e);
        } catch (\Throwable $e) {
            throw new ToolCallException('The workflow could not be read. The error has been logged.', previous: $e);
        }
    }
}
