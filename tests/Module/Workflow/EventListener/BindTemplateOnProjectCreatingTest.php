<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\LabelTone;
use App\Module\Board\EventListener\SeedBoardColumnsOnProjectCreating;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\EventListener\BindTemplateOnProjectCreating;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class BindTemplateOnProjectCreatingTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_lifecycle_seeds_one_column_per_slot_links_each_slot_and_binds_the_project(): void
    {
        $project = $this->create('lifecycle');

        self::assertSame(
            [
                ['backlog', 'board.card.status.backlog', LabelTone::Neutral, false, true],
                ['next', 'workflow.slot.next', LabelTone::Lime, false, false],
                ['product-design', 'workflow.slot.product_design', LabelTone::Sky, false, false],
                ['tech-design', 'workflow.slot.tech_design', LabelTone::Indigo, false, false],
                ['implementation', 'workflow.slot.implementation', LabelTone::Purple, false, false],
                ['in-review', 'workflow.slot.in_review', LabelTone::Amber, false, false],
                ['done', 'board.card.status.done', LabelTone::Green, true, false],
            ],
            $this->columns($project),
        );

        $links = $this->links($project);
        self::assertCount(5, $links);
        foreach ($links as $link) {
            self::assertNotNull($link->column);
            self::assertSame($link->slotKey, $link->column->slug);
        }

        $binding = $this->bindings()->findOneByProjectId($project->id ?? throw new \LogicException());
        self::assertNotNull($binding);
        self::assertSame('lifecycle', $binding->templateKey);
        self::assertSame(1, $binding->templateVersion);
        self::assertEquals($this->shipped()->source('lifecycle'), $binding->definition);
    }

    public function test_simple_keeps_the_four_default_columns_and_binds_the_project(): void
    {
        $project = $this->create('simple');

        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column($this->columns($project), 0));
        self::assertSame([], $this->links($project));
        $binding = $this->bindings()->findOneByProjectId($project->id ?? throw new \LogicException());
        self::assertNotNull($binding);
        self::assertSame('simple', $binding->templateKey);
    }

    public function test_no_template_keeps_the_four_default_columns_and_binds_the_default_template(): void
    {
        $project = $this->create(null);

        self::assertSame(['backlog', 'next', 'in-progress', 'done'], array_column($this->columns($project), 0));
        self::assertSame([], $this->links($project));
        self::assertSame('simple', $this->bindings()->findOneByProjectId($project->id ?? throw new \LogicException())?->templateKey);
    }

    public function test_an_unknown_template_is_a_field_error(): void
    {
        $listener = self::getContainer()->get(BindTemplateOnProjectCreating::class);
        self::assertInstanceOf(BindTemplateOnProjectCreating::class, $listener);
        $event = new ProjectCreating(new Project($this->owner(), 'unknown-'.uniqid()), 'kanban');

        try {
            $listener($event);
            self::fail('Expected DomainErrors for an unknown template.');
        } catch (DomainErrors $e) {
            self::assertSame(['workflowTemplate' => 'workflow.bind.error.unknown_template'], $e->errors);
        }
        self::assertFalse($event->columnsSeeded);
    }

    public function test_the_workflow_listener_runs_before_the_default_column_seeder(): void
    {
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        $order = [];
        foreach ($events->getListeners(ProjectCreating::class) as $listener) {
            $class = \is_array($listener) ? $listener[0]::class : (\is_object($listener) ? $listener::class : null);
            $order[] = $class;
        }

        $workflow = array_search(BindTemplateOnProjectCreating::class, $order, true);
        $board = array_search(SeedBoardColumnsOnProjectCreating::class, $order, true);
        self::assertIsInt($workflow, implode(', ', array_map(strval(...), $order)));
        self::assertIsInt($board);
        self::assertLessThan($board, $workflow);
    }

    private function create(?string $template): Project
    {
        $handler = self::getContainer()->get(CreateProjectHandler::class);
        self::assertInstanceOf(CreateProjectHandler::class, $handler);

        $project = $handler(new CreateProjectCommand($this->owner(), 'workflow-'.uniqid(), null, SearchLanguage::English, workflowTemplate: $template));
        $this->em->clear();

        return $project;
    }

    /** @return list<array{string, string, LabelTone, bool, bool}> */
    private function columns(Project $project): array
    {
        $fresh = $this->em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $fresh);
        $columns = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $columns);

        return array_map(
            static fn (BoardColumn $column): array => [$column->slug, $column->label, $column->tone, $column->terminal, $column->backlog],
            $columns->findForProject($fresh),
        );
    }

    /** @return list<WorkflowSlotLink> */
    private function links(Project $project): array
    {
        $links = self::getContainer()->get(WorkflowSlotLinkRepository::class);
        self::assertInstanceOf(WorkflowSlotLinkRepository::class, $links);

        return $links->findBy(['project' => $project->id]);
    }

    private function bindings(): WorkflowBindingRepository
    {
        $bindings = self::getContainer()->get(WorkflowBindingRepository::class);
        self::assertInstanceOf(WorkflowBindingRepository::class, $bindings);

        return $bindings;
    }

    private function shipped(): ShippedTemplates
    {
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        return $shipped;
    }

    private function owner(): User
    {
        $owner = new User(fullName: 'Riley', email: 'workflow-bind-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->em->flush();

        return $owner;
    }
}
