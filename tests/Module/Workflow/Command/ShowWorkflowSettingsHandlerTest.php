<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BoundWorkflowView;
use App\Module\Workflow\Command\ShowWorkflowSettingsCommand;
use App\Module\Workflow\Command\ShowWorkflowSettingsHandler;
use App\Module\Workflow\Command\WorkflowManualMoveView;
use App\Module\Workflow\Command\WorkflowRuleView;
use App\Module\Workflow\Command\WorkflowSettingsView;
use App\Module\Workflow\Command\WorkflowSlotView;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShowWorkflowSettingsHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_unbound_project_has_no_template(): void
    {
        $project = $this->workflowProject('workflow-settings-unbound');

        $view = $this->show($project);

        self::assertSame($project->id?->toRfc4122(), $view->project->id?->toRfc4122());
        self::assertNull($view->template);
    }

    public function test_a_lifecycle_project_shows_its_slots_rules_moves_and_timings(): void
    {
        $project = $this->workflowProject('workflow-settings-lifecycle');
        $binding = $this->bindLifecycle($project);
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame('lifecycle', $template->key);
        self::assertSame('workflow.template.lifecycle.label', $template->labelKey);
        self::assertSame('workflow.template.lifecycle.description', $template->descriptionKey);
        self::assertSame(1, $template->version);
        self::assertSame($binding->boundAt->format('Y-m-d H:i:s'), $template->boundAt->format('Y-m-d H:i:s'));
        self::assertEquals([
            new WorkflowSlotView('next', 'workflow.slot.next', 'board.card.status.next'),
            new WorkflowSlotView('product-design', 'workflow.slot.product_design', 'product-design'),
            new WorkflowSlotView('tech-design', 'workflow.slot.tech_design', 'tech-design'),
            new WorkflowSlotView('implementation', 'workflow.slot.implementation', 'board.card.status.in-progress'),
            new WorkflowSlotView('in-review', 'workflow.slot.in_review', 'in-review'),
        ], $template->slots);

        $rules = [];
        foreach ($template->rules as $rule) {
            $rules[$rule->id] = $rule;
        }
        self::assertEquals(new WorkflowRuleView('product-design-approved', 'workflow.slot.product_design', 'workflow.settings.action.move', 'workflow.slot.tech_design', null), $rules['product-design-approved']);
        self::assertEquals(new WorkflowRuleView('implement', 'workflow.slot.implementation', 'workflow.settings.action.request', null, 'implement'), $rules['implement']);
        self::assertEquals(new WorkflowRuleView('rebase-stacked', 'workflow.slot.in_review', 'workflow.settings.action.forge_write', null, 'change-base'), $rules['rebase-stacked']);
        self::assertSame('product-design-session', $template->rules[0]->id);

        self::assertEquals(new WorkflowManualMoveView('workflow.settings.where.backlog', 'workflow.slot.next'), $template->manualMoves[0]);
        self::assertSame([10, 60, 360], $template->backoffMinutes);
        self::assertSame(120, $template->workTimeoutMinutes);
    }

    public function test_a_slot_whose_column_was_deleted_has_no_column(): void
    {
        $project = $this->workflowProject('workflow-settings-deleted-column');
        $this->bindLifecycle($project);
        $this->em()->remove($this->column($project, 'tech-design'));
        $this->em()->flush();
        $this->em()->clear();

        $slots = ($this->show($project)->template ?? throw new \LogicException('The project is not bound.'))->slots;

        $columns = array_combine(
            array_map(static fn (WorkflowSlotView $slot): string => $slot->key, $slots),
            array_map(static fn (WorkflowSlotView $slot): ?string => $slot->columnLabel, $slots),
        );
        self::assertNull($columns['tech-design']);
        self::assertSame('product-design', $columns['product-design']);
    }

    public function test_the_simple_template_reads_its_column_flags(): void
    {
        $project = $this->workflowProject('workflow-settings-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame([], $template->slots);
        self::assertEquals([
            new WorkflowRuleView('merged', 'workflow.settings.where.any', 'workflow.settings.action.move', 'workflow.settings.where.any_terminal', null),
            new WorkflowRuleView('closed-unmerged', 'workflow.settings.where.any', 'workflow.settings.action.move', 'workflow.settings.where.backlog', null),
        ], $template->rules);
        self::assertEquals([new WorkflowManualMoveView('workflow.settings.where.any', 'workflow.settings.where.any')], $template->manualMoves);
    }

    private function show(Project $project): WorkflowSettingsView
    {
        $handler = self::getContainer()->get(ShowWorkflowSettingsHandler::class);
        self::assertInstanceOf(ShowWorkflowSettingsHandler::class, $handler);
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        return $handler(new ShowWorkflowSettingsCommand($project));
    }
}
