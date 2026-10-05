<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BoundWorkflowView;
use App\Module\Workflow\Command\ShowWorkflowSettingsCommand;
use App\Module\Workflow\Command\ShowWorkflowSettingsHandler;
use App\Module\Workflow\Command\WorkflowConditionGroupView;
use App\Module\Workflow\Command\WorkflowManualMoveView;
use App\Module\Workflow\Command\WorkflowRuleView;
use App\Module\Workflow\Command\WorkflowSettingsView;
use App\Module\Workflow\Command\WorkflowSlotView;
use App\Module\Workflow\Entity\WorkflowBinding;
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
        self::assertEquals(new WorkflowRuleView('product-design-approved', 'workflow.slot.product_design', 'workflow.settings.action.move', 'workflow.slot.tech_design', null, [new WorkflowConditionGroupView('workflow.source.board', ['card.document_approved', 'card.document_changes_requested'])], []), $rules['product-design-approved']);
        self::assertEquals(new WorkflowRuleView('implement', 'workflow.slot.implementation', 'workflow.settings.action.request', null, 'implement', [new WorkflowConditionGroupView('workflow.source.board', ['card.type']), new WorkflowConditionGroupView('workflow.source.forge', ['pr.linked'])], []), $rules['implement']);
        self::assertEquals(new WorkflowRuleView('rebase-stacked', 'workflow.slot.in_review', 'workflow.settings.action.forge_write', null, 'change-base', [new WorkflowConditionGroupView('workflow.source.forge', ['pr.open', 'pr.stacked', 'pr.parent_merged'])], []), $rules['rebase-stacked']);
        self::assertSame('product-design-session', $template->rules[0]->id);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', ['card.in_slot', 'card.children_finished']),
            new WorkflowConditionGroupView('workflow.source.forge', ['pr.all_finished_one_merged']),
        ], $rules['merged']->conditionGroups);
        self::assertEquals([
            new WorkflowConditionGroupView('workflow.source.board', ['card.document_approved', 'card.document_changes_requested', 'card.has_open_blocker']),
        ], $rules['tech-design-approved']->conditionGroups);

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
            new WorkflowRuleView('merged', 'workflow.settings.where.any', 'workflow.settings.action.move', 'workflow.settings.where.any_terminal', null, [new WorkflowConditionGroupView('workflow.source.board', ['card.in_slot', 'card.children_finished']), new WorkflowConditionGroupView('workflow.source.forge', ['pr.all_finished_one_merged'])], []),
            new WorkflowRuleView('teardown', 'workflow.settings.where.any_terminal', 'workflow.settings.action.request', null, 'teardown', [], []),
        ], $template->rules);
        self::assertEquals([new WorkflowManualMoveView('workflow.settings.where.any', 'workflow.settings.where.any')], $template->manualMoves);
    }

    public function test_a_stored_copy_that_names_a_condition_this_instance_lacks_still_shows(): void
    {
        $project = $this->workflowProject('workflow-settings-missing-condition');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'slots' => [],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [
                ['id' => 'gone', 'when' => ['card.gone' => []], 'then' => ['request' => ['kind' => 'gone']]],
                ['id' => 'work', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'work']]],
                ['id' => 'paused', 'when' => ['card.is_child' => []], 'then' => ['pause' => ['reason' => 'held', 'until' => ['pr.lost' => []]]]],
            ],
        ]));
        $this->em()->flush();
        $this->em()->clear();

        $template = $this->show($project)->template;

        self::assertInstanceOf(BoundWorkflowView::class, $template);
        self::assertSame(['gone', 'work', 'paused'], array_map(static fn (WorkflowRuleView $rule): string => $rule->id, $template->rules));
        self::assertSame(['card.gone'], $template->rules[0]->missingConditions);
        self::assertSame([], $template->rules[0]->conditionGroups);
        self::assertSame([], $template->rules[1]->missingConditions);
        self::assertSame([], $template->rules[1]->conditionGroups);
        self::assertSame(['pr.lost'], $template->rules[2]->missingConditions);
        self::assertEquals([new WorkflowConditionGroupView('workflow.source.board', ['card.is_child'])], $template->rules[2]->conditionGroups);
    }

    private function show(Project $project): WorkflowSettingsView
    {
        $handler = self::getContainer()->get(ShowWorkflowSettingsHandler::class);
        self::assertInstanceOf(ShowWorkflowSettingsHandler::class, $handler);
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        return $handler(new ShowWorkflowSettingsCommand($project));
    }
}
