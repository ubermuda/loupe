<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\WorkflowRowCleanup;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BindWorkflowTemplateHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    private RecordingAuditor $audit;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        // Before the handler is fetched: the container hands the replacement only to what it builds afterwards.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->project = $this->workflowProject('workflow-bind');
    }

    public function test_it_stores_the_template_copy_and_one_link_per_slot(): void
    {
        $columns = $this->lifecycleColumns($this->project);

        $binding = $this->bindLifecycle($this->project);
        $this->em()->clear();

        $stored = $this->bindings()->findOneByProjectId($this->project->id ?? throw new \LogicException());
        self::assertNotNull($stored);
        self::assertSame('lifecycle', $stored->templateKey);
        self::assertSame(1, $stored->templateVersion);
        self::assertEquals($this->shipped()->source('lifecycle'), $stored->definition);

        $links = [];
        foreach ($this->links()->findBy(['project' => $this->project->id]) as $link) {
            $links[$link->slotKey] = $link->columnId?->toRfc4122();
        }
        ksort($links);
        $expected = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $columns);
        ksort($expected);
        self::assertSame($expected, $links);

        self::assertSame(
            ['bindingId' => (string) $binding->id, 'projectId' => (string) $this->project->id, 'templateKey' => 'lifecycle', 'templateVersion' => 1],
            $this->audit->record('workflow.template_bound')->context,
        );
    }

    public function test_a_template_with_no_slot_binds_with_no_link(): void
    {
        $this->bindHandler()(new BindWorkflowTemplateCommand($this->project, 'simple', []));

        self::assertNotNull($this->bindings()->findOneByProjectId($this->project->id ?? throw new \LogicException()));
        self::assertSame([], $this->links()->findBy(['project' => $this->project->id]));
    }

    public function test_it_refuses_a_project_that_is_already_bound(): void
    {
        $this->bindLifecycle($this->project);

        $this->assertRefused(
            ['project' => 'workflow.bind.error.already_bound'],
            new BindWorkflowTemplateCommand($this->project, 'simple', []),
        );
        self::assertCount(1, $this->bindings()->findBy(['project' => $this->project->id]));
    }

    public function test_it_refuses_an_unknown_template(): void
    {
        $this->assertRefused(
            ['templateKey' => 'workflow.bind.error.unknown_template'],
            new BindWorkflowTemplateCommand($this->project, 'kanban', []),
        );
    }

    public function test_it_refuses_a_slot_with_no_column(): void
    {
        $columns = $this->lifecycleColumns($this->project);
        unset($columns['in-review'], $columns['next']);

        $this->assertRefused(
            ['slotColumns[next]' => 'workflow.bind.error.slot_unlinked', 'slotColumns[in-review]' => 'workflow.bind.error.slot_unlinked'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
    }

    public function test_it_refuses_a_column_for_a_slot_the_template_lacks(): void
    {
        $columns = $this->lifecycleColumns($this->project);
        $columns['qa'] = $columns['next'];

        $this->assertRefused(
            ['slotColumns[qa]' => 'workflow.bind.error.unknown_slot'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
    }

    public function test_it_refuses_a_column_of_another_project_or_no_column_at_all(): void
    {
        $other = $this->workflowProject('workflow-bind-other');
        $columns = $this->lifecycleColumns($this->project);
        $columns['tech-design'] = $this->lifecycleColumns($other)['tech-design'];
        $columns['in-review'] = Uuid::v7();

        $this->assertRefused(
            ['slotColumns[tech-design]' => 'workflow.bind.error.foreign_column', 'slotColumns[in-review]' => 'workflow.bind.error.foreign_column'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
    }

    public function test_it_refuses_two_slots_on_one_column(): void
    {
        $columns = $this->lifecycleColumns($this->project);
        $columns['in-review'] = $columns['implementation'];

        $this->assertRefused(
            ['slotColumns[in-review]' => 'workflow.bind.error.column_reused'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
    }

    public function test_it_refuses_a_slot_on_the_backlog(): void
    {
        $columns = $this->lifecycleColumns($this->project);
        $columns['next'] = $this->column($this->project, 'backlog')->id ?? throw new \LogicException();

        $this->assertRefused(
            ['slotColumns[next]' => 'workflow.bind.error.flag_column'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
    }

    public function test_it_refuses_a_slot_on_a_column_that_another_request_made_terminal(): void
    {
        $columns = $this->lifecycleColumns($this->project);
        $inReview = $this->column($this->project, 'in-review');
        self::assertFalse($inReview->terminal);

        $this->em()->getConnection()->executeStatement('UPDATE board_columns SET terminal = true WHERE id = :id', ['id' => (string) $inReview->id]);

        $this->assertRefused(
            ['slotColumns[in-review]' => 'workflow.bind.error.flag_column'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
    }

    public function test_it_refuses_a_column_that_another_request_deleted(): void
    {
        $columns = $this->lifecycleColumns($this->project);
        $this->em()->getConnection()->executeStatement('DELETE FROM board_columns WHERE id = :id', ['id' => $columns['in-review']->toRfc4122()]);

        $this->assertRefused(
            ['slotColumns[in-review]' => 'workflow.bind.error.foreign_column'],
            new BindWorkflowTemplateCommand($this->project, 'lifecycle', $columns),
        );
        self::assertTrue($this->em()->isOpen());
    }

    public function test_a_deleted_column_leaves_the_link_with_no_column(): void
    {
        $this->bindLifecycle($this->project);
        $column = $this->column($this->project, 'in-review');
        self::assertNotNull($column->id);

        $this->em()->getConnection()->executeStatement('DELETE FROM board_columns WHERE id = :id', ['id' => $column->id->toRfc4122()]);
        $cleanup = self::getContainer()->get(WorkflowRowCleanup::class);
        self::assertInstanceOf(WorkflowRowCleanup::class, $cleanup);
        $cleanup->forgetColumn($column->id);
        $this->em()->clear();

        $link = $this->links()->findOneBy(['project' => $this->project->id, 'slotKey' => 'in-review']);
        self::assertInstanceOf(WorkflowSlotLink::class, $link);
        self::assertNull($link->columnId);
        $sibling = $this->links()->findOneBy(['project' => $this->project->id, 'slotKey' => 'next']);
        self::assertNotNull($sibling?->columnId);
    }

    /** @param non-empty-array<string, string> $errors */
    private function assertRefused(array $errors, BindWorkflowTemplateCommand $command): void
    {
        $before = \count($this->bindings()->findAll());
        $recorded = \count($this->audit->records('workflow.template_bound'));
        try {
            $this->bindHandler()($command);
            self::fail('The bind must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertCount($before, $this->bindings()->findAll());
        self::assertCount($recorded, $this->audit->records('workflow.template_bound'));
    }

    private function bindings(): WorkflowBindingRepository
    {
        $repository = self::getContainer()->get(WorkflowBindingRepository::class);
        self::assertInstanceOf(WorkflowBindingRepository::class, $repository);

        return $repository;
    }

    private function links(): WorkflowSlotLinkRepository
    {
        $repository = self::getContainer()->get(WorkflowSlotLinkRepository::class);
        self::assertInstanceOf(WorkflowSlotLinkRepository::class, $repository);

        return $repository;
    }

    private function shipped(): ShippedTemplates
    {
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        return $shipped;
    }
}
